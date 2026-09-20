<?php
/**
 * Cleanup engine — dashboard widget removal, admin-bar decluttering and footer/version hiding, all
 * driven by the settings array. Plus the admin-post handlers for One-Click Cleanup, Restore
 * (each nonce + cap guarded, PRG back to the page).
 */

defined('ABSPATH') || exit;

/* ----------------------------- dashboard widgets ------------------------ */

/** Remove dashboard widgets + the welcome panel per settings (on wp_dashboard_setup). */
function devdadcl_clean_dashboard()
{
    $s = devdadcl_get_settings();

    if (!empty($s['hide_welcome_panel'])) {
        remove_action('welcome_panel', 'wp_welcome_panel');
    }

    // Core widgets: [setting_flag => [widget_id, context]].
    // Core widgets: [setting_flag => [[widget_id, context], ...]]. The PHP / browser update nags ride the Site Health
    // switch, the network At a Glance rides At a Glance (Codex round 3: three core boxes had no switch and survived).
    $map = array(
        'hide_at_a_glance'  => array(array('dashboard_right_now', 'normal'), array('network_dashboard_right_now', 'normal')),
        'hide_activity'     => array(array('dashboard_activity', 'normal')),
        'hide_quick_draft'  => array(array('dashboard_quick_press', 'side')),
        'hide_primary_news' => array(array('dashboard_primary', 'side')),
        'hide_site_health'  => array(array('dashboard_site_health', 'normal'), array('dashboard_php_nag', 'normal'), array('dashboard_browser_nag', 'normal')),
    );
    // The network dashboard registers its boxes under its own screen id (DeepSeek 2026-09-14).
    $screen = ('wp_network_dashboard_setup' === current_action()) ? 'dashboard-network' : 'dashboard';
    foreach ($map as $flag => $boxes) {
        if (!empty($s[$flag])) {
            foreach ($boxes as $box) {
                remove_meta_box($box[0], $screen, $box[1]);
            }
        }
    }
    // Every widget a plugin or theme added (owner 2026-09-14: "one click, all gone"): everything registered on this
    // screen that is not one of the core boxes above. Site Health and Right Now stay under their own switches.
    if (!empty($s['hide_plugin_widgets']) && isset($GLOBALS['wp_meta_boxes'][$screen]) && is_array($GLOBALS['wp_meta_boxes'][$screen])) {
        $core = array('dashboard_right_now', 'dashboard_activity', 'dashboard_quick_press', 'dashboard_primary', 'dashboard_site_health', 'network_dashboard_right_now', 'dashboard_php_nag', 'dashboard_browser_nag');
        foreach ($GLOBALS['wp_meta_boxes'][$screen] as $context => $priorities) {
            foreach ((array) $priorities as $priority => $boxes) {
                foreach ((array) $boxes as $box_id => $box) {
                    if (!in_array((string) $box_id, $core, true)) {
                        remove_meta_box($box_id, $screen, $context);
                    }
                }
            }
        }
    }
}
add_action('wp_dashboard_setup', 'devdadcl_clean_dashboard', PHP_INT_MAX); // after every plugin registration (DeepSeek round 2)
add_action('wp_network_dashboard_setup', 'devdadcl_clean_dashboard', PHP_INT_MAX);

/* ----------------------------- admin bar -------------------------------- */

/**
 * Remove admin-bar nodes per settings (on admin_bar_menu, late). This fires on EVERY logged-in page,
 * front and back, so the read is the memoized single autoloaded option and the work is one pass over
 * a fixed 5-entry map — no per-item query, no menu re-scan.
 */
function devdadcl_clean_admin_bar($wp_admin_bar)
{
    $s = devdadcl_get_settings();

    $map = array(
        'hide_ab_wp_logo'     => 'wp-logo',
        'hide_ab_comments'    => 'comments',
        'hide_ab_new_content' => 'new-content',
        'hide_ab_updates'     => 'updates',
        'hide_ab_customize'   => 'customize',
    );
    foreach ($map as $flag => $node_id) {
        if (!empty($s[$flag])) {
            $wp_admin_bar->remove_node($node_id);
        }
    }
    // Every top-level item a plugin or theme added (owner 2026-09-14): whatever is not a core node and not our bell.
    if (!empty($s['hide_plugin_bar_items']) && method_exists($wp_admin_bar, 'get_nodes')) {
        $core = array('menu-toggle', 'wp-logo', 'site-name', 'updates', 'comments', 'new-content', 'customize', 'my-account', 'top-secondary', 'search', 'edit', 'view', 'archive', 'preview', 'user-actions', 'user-info', 'edit-profile', 'logout', 'my-sites', 'my-sites-list', 'my-sites-super-admin', 'network-admin', 'site-editor', 'devdadcl-bell');
        foreach ((array) $wp_admin_bar->get_nodes() as $node) {
            // Top level, or parked in core's right-hand group (top-secondary): both are "toolbar items" to the owner
            // (Codex round 2: a plugin node under top-secondary survived Clean My Admin).
            if ((empty($node->parent) || 'top-secondary' === (string) $node->parent) && !in_array((string) $node->id, $core, true)) {
                $wp_admin_bar->remove_node($node->id);
            }
        }
    }
}
add_action('admin_bar_menu', 'devdadcl_clean_admin_bar', 9999);

/* ----------------------------- plugins list rows ------------------------ */

/**
 * The notice rows plugins print under their own entry on the Plugins list ("Upgrade to Pro", licence nags, update rows)
 * hang on after_plugin_row / after_plugin_row_<file>. With the switch on every callback on those hooks is dropped for
 * this request (owner 2026-09-15: one click, all gone); the Plugins list itself is untouched.
 */
function devdadcl_hide_plugin_rows()
{
    if (!devdadcl_get_int('hide_plugin_row_notices', 0)) {
        return;
    }
    global $wp_filter;
    if (!is_array($wp_filter)) {
        return;
    }
    foreach (array_keys($wp_filter) as $hook) {
        if (0 === strpos((string) $hook, 'after_plugin_row')) {
            remove_all_actions($hook);
        }
    }
    add_action('after_plugin_row', 'devdadcl_hide_plugin_rows_late', 1); // callbacks added after load-plugins.php
}
function devdadcl_hide_plugin_rows_late()
{
    global $wp_filter;
    foreach (array_keys((array) $wp_filter) as $hook) {
        if (0 === strpos((string) $hook, 'after_plugin_row_')) {
            remove_all_actions($hook);
        }
    }
}
add_action('load-plugins.php', 'devdadcl_hide_plugin_rows', 999);
add_action('load-plugins-network.php', 'devdadcl_hide_plugin_rows', 999);

/* ----------------------------- footer / version ------------------------- */

/** Hide the admin footer text per settings. */
function devdadcl_footer_text($text)
{
    return devdadcl_get_int('hide_footer_text', 0) ? '' : $text;
}
add_filter('admin_footer_text', 'devdadcl_footer_text', 999);

/** Hide the WordPress version in the admin footer per settings. */
function devdadcl_footer_version($version)
{
    return devdadcl_get_int('hide_footer_version', 0) ? '' : $version;
}
add_filter('update_footer', 'devdadcl_footer_version', 999);

/* ----------------------------- admin-post handlers ---------------------- */

/**
 * The Clean My Admin action itself, shared by the screen button and the clean-my-admin ability (2026-09-14).
 * @return array{ok:bool,muted:int} ok=false when the snapshot or the settings write did not land (nothing else ran).
 */
/**
 * Every settings change goes through here (Clean My Admin, the Save button, the update-settings ability), so Undo
 * always reverses the LAST change and never silently a manual edit made after a cleanup (DeepSeek round 6).
 * Settings FIRST: a write that does not land leaves the previous recovery point untouched (Codex rounds 4-5). Then
 * the snapshot OF THE PREVIOUS settings; without it the change must not stand, so the settings are rolled back.
 * A change that changes nothing keeps the previous snapshot (Codex 2026-09-14: clean / clean / undo went back to nothing).
 * @return array{ok:bool,settings_saved:bool,snapshot:bool,changed:bool}
 */
function devdadcl_save_settings_with_undo($settings, $label)
{
    $current = devdadcl_get_settings();
    if ($settings == $current) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- ints vs strings from the form
        return array('ok' => true, 'settings_saved' => true, 'snapshot' => true, 'changed' => false);
    }
    if (!devdadcl_save_settings($settings)) {
        return array('ok' => false, 'settings_saved' => false, 'snapshot' => true, 'changed' => false);
    }
    if (!devdadcl_snapshot_settings($label, $current)) {
        $rolled = devdadcl_save_settings($current);
        return array('ok' => false, 'settings_saved' => !$rolled, 'snapshot' => false, 'changed' => !$rolled);
    }
    return array('ok' => true, 'settings_saved' => true, 'snapshot' => true, 'changed' => true);
}

function devdadcl_apply_cleanup()
{
    $current  = devdadcl_get_settings();
    $settings = array_merge($current, devdadcl_recommended_preset());
    $settings['cleanup_applied'] = 1;
    $w = devdadcl_save_settings_with_undo($settings, __('One-Click Cleanup', 'devdome-admin-cleaner'));
    if (empty($w['ok'])) {
        return array('ok' => false, 'muted' => 0, 'failed' => 0, 'settings_saved' => $w['settings_saved'], 'snapshot' => $w['snapshot']);
    }
    // Hide every notice showing right now (owner 2026-09-14) when the setting allows it.
    $muted = 0;
    if (!empty($settings['mute_promos_on_cleanup'])) {
        $muted = devdadcl_mute_current_promos();
    }
    if (function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    $failed = isset($GLOBALS['devdadcl_mute_failed']) ? (int) $GLOBALS['devdadcl_mute_failed'] : 0;
    return array('ok' => 0 === $failed, 'muted' => (int) $muted, 'failed' => $failed, 'settings_saved' => true);
}

/** One-Click Cleanup (the screen button). */
function devdadcl_handle_oneclick()
{
    if (!current_user_can(devdadcl_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-admin-cleaner'));
    }
    check_admin_referer('devdadcl_oneclick', '_acnonce');
    devdadcl_db_guard_begin(); // guard window (DESIGN.md 24)
    $r = devdadcl_apply_cleanup();
    if (empty($r['ok'])) {
        // A lost setting or mute write is never "Cleanup applied". The two cases read differently (DeepSeek round 2):
        // the settings never landed (nothing changed) vs the settings landed and some notices stayed visible.
        $why = empty($r['settings_saved']) ? 'save' : (isset($r['snapshot']) && false === $r['snapshot'] ? 'snapshot' : 'mute');
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'overview', 'ac_error' => $why));
    }
    devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'overview', 'ac_cleaned' => '1', 'ac_muted' => (int) $r['muted']));
}
add_action('admin_post_devdadcl_oneclick', 'devdadcl_handle_oneclick');

/** Clear all mutes (every scope, every user) so every hidden notice comes back. */
/** Every mute row deleted (proved); shared by the screen button and the clear-all-mutes ability. @return int rows gone. */
function devdadcl_clear_all_mutes()
{
    $n = 0;
    $GLOBALS['devdadcl_unmute_failed'] = 0; // rows that stayed (DeepSeek round 2: "every notice is visible again" on a partial result)
    foreach (devdadcl_option_keys('devdadcl_m_') as $name) {
        if (devdadcl_option_delete($name)) { // counted only when the row is gone (DESIGN.md 24.5)
            $n++;
        } else {
            $GLOBALS['devdadcl_unmute_failed']++;
        }
    }
    // A pending auto-hide would hide the notice again on the next capture (Codex round 2): cleared with the mutes.
    foreach (devdadcl_get_inbox() as $hash => $rec) {
        if (!empty($rec['auto_hide_pending'])) {
            unset($rec['auto_hide_pending']);
            if (!devdadcl_put_record($hash, $rec)) {
                $GLOBALS['devdadcl_unmute_failed']++;
            }
        }
    }
    return $n;
}

function devdadcl_handle_clear_mutes()
{
    if (!current_user_can(devdadcl_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-admin-cleaner'));
    }
    check_admin_referer('devdadcl_clear_mutes', '_acnonce');
    devdadcl_db_guard_begin(); // guard window (DESIGN.md 24)
    // The dialog's OK sends ac_confirmed=1 (ac-admin.js); the server needs it too (DeepSeek round 6: client-only gate).
    if (!isset($_POST['ac_confirmed']) || '1' !== sanitize_text_field(wp_unslash($_POST['ac_confirmed']))) {
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'overview', 'ac_error' => 'confirm'));
    }
    $n = devdadcl_clear_all_mutes();
    if (function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    if (!empty($GLOBALS['devdadcl_unmute_failed'])) {
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'overview', 'ac_error' => 'save')); // some mutes stayed: never "every notice is visible again"
    }
    devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'overview', 'ac_unmuted' => (int) $n));
}
add_action('admin_post_devdadcl_clear_mutes', 'devdadcl_handle_clear_mutes');

/**
 * Undo last change: exact restoration of the settings array as it was before the most
 * recent one-click (one level). Notice mutes made by that operation are
 * user-visible in the inbox and restorable there; settings are what this reverses exactly.
 */
/** Undo: the snapshot written back (proved), consumed on success; shared by the screen button and the ability. */
function devdadcl_undo_last_change()
{
    $snap = devdadcl_last_change();
    if ($snap === null) {
        return false;
    }
    if (!devdadcl_save_settings($snap['settings'])) {
        return 'failed'; // a restore that did not land is never "Nothing to undo" (DeepSeek round 5)
    }
    // one-shot: undo consumed. Settings restored but a snapshot that stayed is 'partial', never "Nothing to undo"
    // (DeepSeek round 2): Undo will offer the same snapshot again.
    return devdadcl_option_delete('devdadcl_last_change') ? true : 'partial';
}

function devdadcl_handle_undo()
{
    if (!current_user_can(devdadcl_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-admin-cleaner'));
    }
    check_admin_referer('devdadcl_undo', '_acnonce');
    devdadcl_db_guard_begin(); // guard window (DESIGN.md 24)
    $r  = devdadcl_undo_last_change();
    $ok = (true === $r) ? 1 : ('partial' === $r ? 2 : 0);
    if (function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    if ('failed' === $r) {
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'overview', 'ac_error' => 'save'));
    }
    devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'overview', 'ac_undone' => $ok));
}
add_action('admin_post_devdadcl_undo', 'devdadcl_handle_undo');

