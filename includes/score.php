<?php
/**
 * Workspace clutter estimate + the cached hub summary. (Rewritten in audit batch 4.)
 *
 * THE METRIC IS A PREFERENCE ESTIMATE, NOT A HEALTH OR SECURITY SCORE. Every field has one
 * documented definition below, the formula is published (and shown in the UI), and nothing
 * in it rewards hiding an operational surface: Site Health and the Updates indicator are
 * NOT counted as clutter, protected notices are NOT counted as clutter, and a muted promo
 * stops contributing the moment it is muted.
 *
 * Field definitions (all computed in devdadcl_refresh_summary()):
 *   notices_seen      — notice records last seen within the past 30 days (stale history
 *                       beyond that is retained but not reported as current).
 *   muted_count       — records with a mute in force RIGHT NOW, any scope (orphan mutes
 *                       are GC'd and never counted).
 *   promo_count       — promotional records, active window, with NO mute in force —
 *                       i.e. promos an administrator can actually still see.
 *   critical_count    — protected records, active window, not hidden by an all-admins
 *                       mute (a per-user hide leaves it visible to other admins).
 *   active_widgets    — CONFIGURED built-in dashboard widgets not hidden by settings.
 *                       Site Health is deliberately excluded: hiding it must never
 *                       improve any number this plugin reports.
 *   admin_bar_items   — configured built-in admin-bar nodes not hidden by settings.
 *                       The Updates indicator is excluded for the same reason.
 *   client_users      — real users currently holding a configured client role. Zero
 *                       users means zero client exposure, whatever the settings say.
 *   score             — 0-100, higher = more cluttered:
 *                         min(40, visible promos x 8)
 *                       + min(25, active_widgets x 5)
 *                       + min(20, admin_bar_items x 5)
 *                       + (Client Mode off AND client_users > 0 ? 15 : 0)
 *
 * The summary is CACHED (option devdadcl_hub_summary) so hub tiles never
 * query on render, and it is refreshed after every state change: capture, mute, restore,
 * delete, purge, retention, settings saves, presets, reset — plus a daily cron. updated_at
 * says when; the UI shows it and offers a manual refresh.
 */

defined('ABSPATH') || exit;

/** Cached hub summary option key. (Renamed from the pre-0.2.0 generic name —
 *  includes/migrate.php drops the old row; the summary recomputes on demand.) */
function devdadcl_summary_option()
{
    return 'devdadcl_hub_summary';
}

/** Records older than this (by last_seen) are history, not current workspace state. */
const DEVDADCL_SUMMARY_WINDOW = 30 * 86400;

/** Full-shape empty summary (used as defaults + the seeded value). */
function devdadcl_empty_summary()
{
    return array(
        'updated_at'        => 0,
        'score'             => 0,   // 0-100 workspace clutter ESTIMATE, higher = more cluttered
        'notices_seen'      => 0,   // records seen in the last 30 days
        'muted_count'       => 0,   // records with a mute in force now (any scope)
        'promo_count'       => 0,   // VISIBLE promos (active window, no mute in force)
        'critical_count'    => 0,   // protected records not hidden by an all-admins mute
        'critical_hidden'   => 0,   // protected records hidden for all admins
        'active_widgets'    => 0,   // configured built-in widgets still shown (Site Health excluded)
        'admin_bar_items'   => 0,   // configured built-in admin-bar nodes still shown (Updates excluded)
        'client_users'      => 0,   // real users holding a configured client role
        'client_risk_count' => 0,   // kept for the hub contract: client_users when Client Mode is off, else 0
        'storage_warning'   => 0,   // retention could not honor the cap without dropping protected records
        'cleanup_applied'   => 0,   // mirror of the settings flag
        'client_mode_on'    => 0,   // mirror of the settings flag
    );
}

/** Read the cached summary (always returns a full-shape array). */
function devdadcl_hub_summary()
{
    $opt = get_option(devdadcl_summary_option(), array());
    return wp_parse_args(is_array($opt) ? $opt : array(), devdadcl_empty_summary());
}

/** Real users currently holding any configured client role (0 when none / unavailable). */
function devdadcl_count_client_users()
{
    if (!function_exists('count_users')) {
        return 0; // test harness / very early bootstrap
    }
    $roles = devdadcl_get_array('client_roles', array());
    if (!$roles) {
        return 0;
    }
    $counts = count_users();
    $avail  = isset($counts['avail_roles']) && is_array($counts['avail_roles']) ? $counts['avail_roles'] : array();
    $n = 0;
    foreach ($roles as $role) {
        $n += isset($avail[$role]) ? (int) $avail[$role] : 0;
    }
    return $n;
}

/**
 * Recompute the summary from current settings + the per-record notice store, then persist
 * it. Reads option data only — no expensive queries beyond one count_users().
 */
function devdadcl_refresh_summary()
{
    $settings = devdadcl_get_settings();
    $inbox    = devdadcl_get_inbox();
    if (!empty($GLOBALS['devdadcl_option_keys_failed'])) {
        return devdadcl_hub_summary(); // the enumeration failed: keep the last verified numbers, never write zeros (Codex 2026-09-14)
    }
    $now      = time();
    $window   = $now - DEVDADCL_SUMMARY_WINDOW;

    // Mute state per record: 'admins' | 'user' | none. Orphans are skipped (GC removes them).
    $mute_scope = array();
    $mute_keys  = devdadcl_option_keys('devdadcl_m_');
    if (!empty($GLOBALS['devdadcl_option_keys_failed'])) {
        return devdadcl_hub_summary(); // the MUTE enumeration failed: keep the last verified numbers (Codex + DeepSeek round 2)
    }
    foreach ($mute_keys as $name) {
        $hash = substr($name, strlen('devdadcl_m_'));
        if (!isset($inbox[$hash])) {
            continue; // orphan — never counted
        }
        if (function_exists('devdadcl_db_reset_error')) {
            devdadcl_db_reset_error();
        }
        $rec = devdadcl_mute_get($hash);
        if (function_exists('devdadcl_db_failed') && devdadcl_db_failed()) {
            return devdadcl_hub_summary(); // one unreadable mute row: keep the last verified numbers (Codex round 7)
        }
        if ($rec === null) {
            continue;
        }
        if (devdadcl_until_active($rec['admins'], $now)) {
            $mute_scope[$hash] = 'admins';
            continue;
        }
        foreach ($rec['users'] as $until) {
            if (devdadcl_until_active($until, $now)) {
                $mute_scope[$hash] = 'user';
                break;
            }
        }
    }

    $notices_seen = 0;
    $promo_count = 0;
    $critical_count = 0;
    $critical_hidden = 0;
    foreach ($inbox as $hash => $n) {
        if ((int) $n['last_seen'] < $window) {
            continue; // history, not current state
        }
        $notices_seen++;
        if (!empty($n['is_critical'])) {
            // "Currently visible critical": an all-admins mute hides it for everyone; a
            // per-user hide leaves it visible to the other administrators.
            if (!isset($mute_scope[$hash]) || $mute_scope[$hash] !== 'admins') {
                $critical_count++;
            } else {
                $critical_hidden++;
            }
        } elseif (!empty($n['is_promo']) && !isset($mute_scope[$hash])) {
            $promo_count++; // a muted promo stops being "clutter" the moment it is muted (any scope: the tested, owner-accepted rule; DeepSeek round 5 rejected)
        }
    }
    $muted_count = count($mute_scope);

    // Configured built-in dashboard widgets still shown. Site Health is NOT in this list on
    // purpose: it is an operational surface, and hiding it must never improve the estimate.
    $widget_flags = array('hide_welcome_panel', 'hide_at_a_glance', 'hide_activity', 'hide_quick_draft', 'hide_primary_news');
    $active_widgets = 0;
    foreach ($widget_flags as $f) {
        if (empty($settings[$f])) {
            $active_widgets++;
        }
    }

    // Configured built-in admin-bar nodes still shown. The Updates indicator is excluded
    // for the same reason as Site Health above.
    $ab_flags = array('hide_ab_wp_logo', 'hide_ab_comments', 'hide_ab_new_content', 'hide_ab_customize');
    $admin_bar_items = 0;
    foreach ($ab_flags as $f) {
        if (empty($settings[$f])) {
            $admin_bar_items++;
        }
    }

    // Client exposure is a FACT (real users in the configured roles), not an invented 5.
    $client_users = devdadcl_count_client_users();
    $client_risk  = (empty($settings['client_mode_on']) && $client_users > 0) ? $client_users : 0;

    // --- the published formula (documented in the file header + shown in the UI) ---
    $score  = 0;
    $score += min(40, $promo_count * 8);
    $score += min(25, $active_widgets * 5);
    $score += min(20, $admin_bar_items * 5);
    $score += $client_risk > 0 ? 15 : 0;
    $score  = (int) max(0, min(100, $score));

    $summary = array(
        'updated_at'        => $now,
        'score'             => $score,
        'notices_seen'      => $notices_seen,
        'muted_count'       => $muted_count,
        'promo_count'       => $promo_count,
        'critical_count'    => $critical_count,
        'critical_hidden'   => $critical_hidden,
        'active_widgets'    => $active_widgets,
        'admin_bar_items'   => $admin_bar_items,
        'client_users'      => $client_users,
        'client_risk_count' => $client_risk,
        'storage_warning'   => (int) get_option('devdadcl_storage_warning', 0),
        'cleanup_applied'   => (int) !empty($settings['cleanup_applied']),
        'client_mode_on'    => (int) !empty($settings['client_mode_on']),
    );

    // Proved like every other write; a summary that did not land is said, never shown as fresh (Codex round 4).
    $GLOBALS['devdadcl_summary_write_failed'] = !devdadcl_option_write(devdadcl_summary_option(), $summary, false);
    return empty($GLOBALS['devdadcl_summary_write_failed']) ? $summary : devdadcl_hub_summary();
}

// Daily cron refresh (GC + retention run first on the same hook, priorities 5/6).
add_action('devdadcl_summary_refresh', 'devdadcl_refresh_summary');

/**
 * Throttled freshness backstop on admin_init: even if no state-changing path fired, the
 * snapshot self-heals at most once every 15 minutes. Every state change ALSO refreshes
 * immediately (handlers + capture), so this is a floor, not the primary mechanism.
 */
function devdadcl_maybe_refresh_summary()
{
    if (!is_admin()) {
        return;
    }
    $s = devdadcl_hub_summary();
    if ((int) $s['updated_at'] + 900 > time()) {
        return;
    }
    devdadcl_refresh_summary();
}
add_action('admin_init', 'devdadcl_maybe_refresh_summary', 99);

/** Manual "Refresh now" for the dashboard tab (nonce + cap, PRG). */
function devdadcl_handle_refresh_summary()
{
    if (!current_user_can(devdadcl_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-admin-cleaner'));
    }
    check_admin_referer('devdadcl_refresh', '_acnonce');
    devdadcl_db_guard_begin(); // guard window (DESIGN.md 24)
    devdadcl_refresh_summary();
    if (!empty($GLOBALS['devdadcl_summary_write_failed'])) {
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'overview', 'ac_error' => 'save'));
    }
    devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'overview', 'ac_refreshed' => '1'));
}
add_action('admin_post_devdadcl_refresh', 'devdadcl_handle_refresh_summary');
