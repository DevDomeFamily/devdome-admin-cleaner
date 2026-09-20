<?php
/**
 * WordPress Abilities API layer (WordPress 6.9+): DevDome Admin Cleaner exposed as typed, discoverable
 * abilities for AI agents and MCP clients (through the official WordPress MCP Adapter).
 *
 * Coverage = every action of the plugin's own screen, audited against the code (2026-09-14):
 * cleanup.php (Clean My Admin, Undo last change, Clear all mutes), admin.php (the three settings tabs +
 * the auto-hide switch), notices.php (every inbox action, Delete all notice data), score.php (refresh).
 * Every write goes through the same shared function the screen uses (one source of truth, same
 * sanitizers, same proved writes). Deleting notice records is destructive and needs confirm: true.
 * On WordPress older than 6.9 the API does not exist and this file registers nothing.
 */

defined('ABSPATH') || exit;

function devdadcl_ability_ids()
{
    return array(
        'devdome-admin-cleaner/get-status',
        'devdome-admin-cleaner/get-settings',
        'devdome-admin-cleaner/get-notices',
        'devdome-admin-cleaner/clean-my-admin',
        'devdome-admin-cleaner/undo-last-change',
        'devdome-admin-cleaner/update-settings',
        'devdome-admin-cleaner/notice-action',
        'devdome-admin-cleaner/delete-notices',
        'devdome-admin-cleaner/clear-all-mutes',
        'devdome-admin-cleaner/delete-notice-data',
        'devdome-admin-cleaner/refresh-summary',
        'devdome-admin-cleaner/get-notice',
        'devdome-admin-cleaner/snooze-notices',
        'devdome-admin-cleaner/run-housekeeping',
    );
}

add_action('wp_abilities_api_categories_init', 'devdadcl_register_ability_category');
function devdadcl_register_ability_category()
{
    if (!function_exists('wp_register_ability_category')) {
        return;
    }
    wp_register_ability_category('devdome-admin-cleaner', array(
        'label'       => __('DevDome Admin Cleaner', 'devdome-admin-cleaner'),
        'description' => __('Clean the WordPress admin: one-click cleanup that hides every dashboard widget, admin-bar extra, footer line and admin notice for all administrators, a notice inbox with snooze / hide / restore per notice, per-item settings, auto-hide for new notices and a cosmetic Client Mode for chosen roles.', 'devdome-admin-cleaner'),
    ));
}

function devdadcl_ability_can()
{
    return current_user_can(devdadcl_capability());
}

/** read / modify / destroy annotations; $idempotent overrides the default for that kind. */
function devdadcl_ability_meta($kind, $idempotent = null)
{
    $map = array(
        'read'    => array('readonly' => true,  'destructive' => false, 'idempotent' => true),
        'modify'  => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
        'destroy' => array('readonly' => false, 'destructive' => true,  'idempotent' => true),
    );
    $ann = isset($map[$kind]) ? $map[$kind] : $map['modify'];
    if (null !== $idempotent) {
        $ann['idempotent'] = (bool) $idempotent;
    }
    return array(
        'public'       => true,
        'show_in_rest' => true,
        'annotations'  => $ann,
        'mcp'          => array('type' => 'tool'),
    );
}

/** confirm: true or a WP_Error the agent must show the user. */
function devdadcl_ability_confirmed($input, $why = '')
{
    if (is_array($input) && isset($input['confirm']) && true === $input['confirm']) {
        return true;
    }
    if ('' === $why) {
        $why = __('This deletes stored notice records and cannot be undone by the agent.', 'devdome-admin-cleaner');
    }
    return new WP_Error('devdadcl_confirm_required', $why . ' ' . __('Pass confirm: true after the user agreed.', 'devdome-admin-cleaner'));
}

/** Output sanitizer at the ability boundary: no e-mail address and no absolute server path reach an agent. */
function devdadcl_ability_strip($v)
{
    if ($v instanceof WP_Error) {
        $e = new WP_Error();
        foreach ($v->get_error_codes() as $code) {
            $data = $v->get_error_data($code);
            foreach ($v->get_error_messages($code) as $m) {
                $e->add($code, devdadcl_ability_strip($m), null === $data ? null : devdadcl_ability_strip($data));
            }
        }
        return $e;
    }
    if (is_object($v)) {
        return (object) devdadcl_ability_strip(get_object_vars($v));
    }
    if (is_array($v)) {
        foreach ($v as $k => $x) {
            $v[$k] = devdadcl_ability_strip($x);
        }
        return $v;
    }
    if (!is_string($v) || '' === $v) {
        return $v;
    }
    // A value that is EXACTLY one of our setting names or a role registered on this site is an identifier the agent has to
    // hand back, never a secret: the redactor's long-token rule turned hide_plugin_row_notices and long role slugs into
    // "[redacted]" (Codex 1.0.7 audit). Whole-value match only, so free text is still redacted.
    if (in_array($v, devdadcl_ability_bool_keys(), true) || 'client_roles' === $v
        || (preg_match('/^[a-z0-9_\-]{1,60}$/', $v) && function_exists('wp_roles') && isset(wp_roles()->roles[$v]))) { // a plain slug only: a role named like an e-mail, a path or a token is still redacted (Codex 1.0.8)
        return $v;
    }
    if (function_exists('devdadcl_redact')) {
        // The store's redactor (keys, quoted values, define(), PEM, URL userinfo) at the agent boundary too. Notice hashes
        // (32 hex) are the agent's handles, not secrets: parked before, put back after.
        $keep = array();
        // Only a hash that names a stored record is a handle; any other 32-hex run (an API key) is redacted like the rest
        // (DeepSeek round 3).
        $v = preg_replace_callback('/\b[a-f0-9]{32}\b/', function ($m) use (&$keep) {
            if (devdadcl_get_record($m[0]) === null) {
                return $m[0];
            }
            $keep[] = $m[0];
            return '__DDHASH' . (count($keep) - 1) . '__';
        }, $v);
        $v = devdadcl_redact($v);
        $v = preg_replace_callback('/__DDHASH(\d+)__/', function ($m) use ($keep) { return isset($keep[(int) $m[1]]) ? $keep[(int) $m[1]] : ''; }, $v);
    }
    $v = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '<email>', $v);
    if (defined('ABSPATH') && '' !== (string) ABSPATH && false !== strpos($v, (string) ABSPATH)) {
        $v = str_replace((string) ABSPATH, '<site>/', $v);
    }
    $v = preg_replace('~(?<![\w:/.])(?:/[A-Za-z0-9._-]+){2,}/?~', '<path>', $v);
    $v = preg_replace('~(?<![\w:/.])/[A-Za-z0-9_-]+\.[A-Za-z0-9]{1,5}\b~', '<path>', $v); // a root-level file (/config.php) (Codex round 5)
    $v = preg_replace('~(?<![\w])[A-Za-z]:[\\\\/][^\s"\'<>]*~', '<path>', $v);
    $v = preg_replace('~(?<![\w])\\\\\\\\[^\s"\'<>]+~', '<path>', $v); // UNC share
    return $v;
}

/* ------------------------------ shared shapes ------------------------------ */

/** The boolean settings an agent may read and change (client_roles is the one array). cleanup_applied is read-only. */
function devdadcl_ability_bool_keys()
{
    return array(
        'auto_hide_new', 'client_mode_on',
        'hide_welcome_panel', 'hide_at_a_glance', 'hide_activity', 'hide_quick_draft', 'hide_primary_news', 'hide_site_health', 'hide_plugin_widgets',
        'hide_ab_wp_logo', 'hide_ab_comments', 'hide_ab_new_content', 'hide_ab_updates', 'hide_ab_customize', 'hide_plugin_bar_items',
        'hide_footer_text', 'hide_footer_version', 'hide_plugin_row_notices', 'show_bell', 'mute_promos_on_cleanup',
        'client_hide_plugins', 'client_hide_tools', 'client_hide_settings', 'client_hide_theme_editor', 'client_hide_plugin_editor', 'client_hide_updates', 'client_hide_version',
    );
}

/** Role slugs registered on this site. */
function devdadcl_ability_roles()
{
    if (!function_exists('wp_roles')) {
        return array();
    }
    $roles = wp_roles();
    if (!isset($roles->roles) || !is_array($roles->roles)) {
        return array();
    }
    // The same choices the screen offers: a role holding manage_options is exempt from Client Mode by capability,
    // so accepting it would promise something the engine cannot do (Codex round 2).
    $out = array();
    foreach ($roles->roles as $slug => $def) {
        $caps = isset($def['capabilities']) && is_array($def['capabilities']) ? $def['capabilities'] : array();
        if (empty($caps['manage_options'])) {
            $out[] = (string) $slug;
        }
    }
    return $out;
}

/** Settings as booleans + the roles array, one shape for get-settings and the update read-back. */
function devdadcl_ability_settings()
{
    $s   = devdadcl_get_settings();
    $out = array();
    foreach (devdadcl_ability_bool_keys() as $k) {
        $out[$k] = !empty($s[$k]);
    }
    $out['cleanup_applied'] = !empty($s['cleanup_applied']);
    $out['client_roles']    = array_values(array_map('strval', isset($s['client_roles']) && is_array($s['client_roles']) ? $s['client_roles'] : array()));
    return $out;
}

/** Strict switch value: bool, 0/1 or the spellings true/false/yes/no/on/off; anything else = null (refused). */
function devdadcl_ability_bool($v)
{
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) && (0 === $v || 1 === $v)) {
        return 1 === $v;
    }
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if (in_array($s, array('1', 'true', 'yes', 'on'), true)) {
            return true;
        }
        if (in_array($s, array('0', 'false', 'no', 'off'), true)) {
            return false;
        }
    }
    return null;
}

/** One inbox row as the agent sees it (excerpts are already redacted by the store). */
function devdadcl_ability_notice_row($hash, $rec, $now, $uid)
{
    $type   = !empty($rec['is_critical']) ? 'critical' : (!empty($rec['is_promo']) ? 'promo' : 'normal');
    $status = 'visible';
    $scope  = '';
    $until  = 0;
    $m      = devdadcl_mute_get($hash);
    if (null !== $m) {
        if (devdadcl_until_active($m['admins'], $now)) {
            $status = (int) $m['admins'] === 0 ? 'hidden' : 'snoozed';
            $scope  = 'admins';
            $until  = (int) $m['admins'];
        } elseif (isset($m['users'][$uid]) && devdadcl_until_active($m['users'][$uid], $now)) {
            $status = (int) $m['users'][$uid] === 0 ? 'hidden' : 'snoozed';
            $scope  = 'user';
            $until  = (int) $m['users'][$uid];
        }
    }
    return array(
        'hash'       => (string) $hash,
        'type'       => $type,
        'status'     => $status,
        'scope'      => $scope,
        'until'      => $until,
        'first_seen' => (int) $rec['first_seen'],
        'last_seen'  => (int) $rec['last_seen'],
        'source'     => (string) $rec['source'],
        'excerpt'    => (string) $rec['excerpt'],
    );
}

/* ------------------------------ handlers ------------------------------ */

function devdadcl_ability_get_status($input = array())
{
    $s   = devdadcl_get_settings();
    $sum = devdadcl_hub_summary();
    $hidden_widgets = 0;
    foreach (array('hide_welcome_panel', 'hide_at_a_glance', 'hide_activity', 'hide_quick_draft', 'hide_primary_news', 'hide_site_health', 'hide_plugin_widgets') as $f) {
        $hidden_widgets += empty($s[$f]) ? 0 : 1;
    }
    $hidden_bar = 0;
    foreach (array('hide_ab_wp_logo', 'hide_ab_comments', 'hide_ab_new_content', 'hide_ab_updates', 'hide_ab_customize', 'hide_plugin_bar_items') as $f) {
        $hidden_bar += empty($s[$f]) ? 0 : 1;
    }
    $snap = devdadcl_last_change();
    return array(
        'cleanup_applied'   => !empty($s['cleanup_applied']),
        'auto_hide_new'     => !empty($s['auto_hide_new']),
        'client_mode_on'    => !empty($s['client_mode_on']),
        'notices_seen'      => (int) $sum['notices_seen'],
        'critical_visible'  => (int) $sum['critical_count'],
        'critical_hidden'   => (int) (isset($sum['critical_hidden']) ? $sum['critical_hidden'] : 0),
        'promos_visible'    => (int) $sum['promo_count'],
        'notices_hidden'    => (int) $sum['muted_count'],
        'hidden_widgets'    => $hidden_widgets,
        'hidden_bar_items'  => $hidden_bar,
        'cleanliness'       => (int) $sum['updated_at'] > 0 ? max(0, min(100, 100 - (int) $sum['score'])) : null,
        'summary_updated_at' => (int) $sum['updated_at'],
        'undo_available'    => null !== $snap,
        'undo_label'        => null !== $snap ? (string) $snap['label'] : '',
        'storage_warning'   => !empty($sum['storage_warning']),
        'plugin_version'    => defined('DEVDADCL_VERSION') ? DEVDADCL_VERSION : '',
    );
}

function devdadcl_ability_get_settings($input = array())
{
    return array('settings' => devdadcl_ability_settings(), 'roles_available' => devdadcl_ability_roles());
}

function devdadcl_ability_get_notices($input = array())
{
    $input  = is_array($input) ? $input : array();
    $status = isset($input['status']) ? sanitize_key((string) $input['status']) : 'all';
    $type   = isset($input['type']) ? sanitize_key((string) $input['type']) : 'all';
    $search = isset($input['search']) ? strtolower(trim((string) $input['search'])) : '';
    $limit  = isset($input['limit']) ? (int) $input['limit'] : 50;
    $limit  = max(1, min(200, $limit));
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;
    $sort   = isset($input['sort']) ? sanitize_key((string) $input['sort']) : 'last_seen';
    $dir    = isset($input['dir']) ? sanitize_key((string) $input['dir']) : 'desc';
    if (!in_array($sort, array('last_seen', 'first_seen', 'type', 'status'), true) || !in_array($dir, array('asc', 'desc'), true)) {
        return new WP_Error('devdadcl_invalid_input', __('sort must be last_seen, first_seen, type or status; dir asc or desc.', 'devdome-admin-cleaner'));
    }
    if (!in_array($status, array('all', 'visible', 'hidden', 'snoozed'), true)) {
        return new WP_Error('devdadcl_invalid_input', __('status must be all, visible, hidden or snoozed.', 'devdome-admin-cleaner'));
    }
    if (!in_array($type, array('all', 'critical', 'promo', 'normal'), true)) {
        return new WP_Error('devdadcl_invalid_input', __('type must be all, critical, promo or normal.', 'devdome-admin-cleaner'));
    }
    $now  = time();
    $uid  = get_current_user_id();
    $rows = array();
    foreach (devdadcl_get_inbox() as $hash => $rec) {
        $row = devdadcl_ability_notice_row($hash, $rec, $now, $uid);
        if ('all' !== $status && $row['status'] !== $status) {
            continue;
        }
        if ('all' !== $type && $row['type'] !== $type) {
            continue;
        }
        if ('' !== $search && false === strpos(strtolower($row['excerpt'] . ' ' . $row['source']), $search)) {
            continue;
        }
        $rows[] = $row;
    }
    usort($rows, function ($a, $b) use ($sort, $dir) {
        $c = is_int($a[$sort]) ? ($a[$sort] <=> $b[$sort]) : strcmp((string) $a[$sort], (string) $b[$sort]);
        return 'desc' === $dir ? -$c : $c;
    });
    $total = count($rows);
    return array('total' => $total, 'offset' => $offset, 'limit' => $limit, 'notices' => array_slice($rows, $offset, $limit));
}

function devdadcl_ability_clean_my_admin($input = array())
{
    $input = is_array($input) ? $input : array();
    if (!isset($input['confirm']) || true !== $input['confirm']) {
        return new WP_Error('devdadcl_confirm_required', __('Clean My Admin hides every widget, toolbar item and notice for every administrator. Pass confirm: true after the site owner agreed.', 'devdome-admin-cleaner'));
    }
    $r = devdadcl_apply_cleanup();
    if (empty($r['ok'])) {
        return new WP_Error('devdadcl_save_failed', !empty($r['failed'])
            /* translators: %d is the number of notices whose mute did not land. */
            ? sprintf(__('%d notices could not be hidden: the database did not keep the change. The rest was applied; run it again.', 'devdome-admin-cleaner'), (int) $r['failed'])
            : ((isset($r['snapshot']) && false === $r['snapshot'] && !empty($r['settings_saved']))
                ? __('The cleanup settings were applied, but the undo snapshot could not be stored and the settings could not be rolled back: undo-last-change will not offer this change.', 'devdome-admin-cleaner')
                : __('The cleanup settings could not be saved: the database did not keep them. Nothing was changed.', 'devdome-admin-cleaner')));
    }
    return array('cleaned' => true, 'notices_hidden' => (int) $r['muted'], 'status' => devdadcl_ability_get_status());
}

function devdadcl_ability_undo_last_change($input = array())
{
    $snap = devdadcl_last_change();
    if (null === $snap) {
        return new WP_Error('devdadcl_nothing_to_undo', __('There is no change to undo.', 'devdome-admin-cleaner'));
    }
    // The same gate as update-settings: turning Auto-hide on hides every future notice for every admin (Codex round 4).
    if (!empty($snap['settings']['auto_hide_new']) && !devdadcl_get_int('auto_hide_new', 0)) {
        $ok = devdadcl_ability_confirmed($input, __('Restoring these settings turns Auto-hide new notices on for every administrator.', 'devdome-admin-cleaner'));
        if (is_wp_error($ok)) {
            return $ok;
        }
    }
    $r = devdadcl_undo_last_change();
    if (true !== $r && 'partial' !== $r) { // false (no snapshot) or 'failed' (restore did not land): never undone (Codex round 6)
        return new WP_Error('devdadcl_save_failed', __('The previous settings could not be written back: the database did not keep them. Nothing was changed.', 'devdome-admin-cleaner'));
    }
    if (function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary(); // the screen handler refreshes too; get-status must not keep the pre-undo score (Codex round 8)
    }
    if ('partial' === $r) {
        // Settings restored, snapshot still there (Codex round 3: a truthy 'partial' read as a complete undo).
        return new WP_Error('devdadcl_undo_partial', __('The previous settings were restored, but the undo snapshot could not be cleared: undo-last-change may offer the same change again.', 'devdome-admin-cleaner'));
    }
    return array('undone' => true, 'label' => (string) $snap['label'], 'settings' => devdadcl_ability_settings());
}

function devdadcl_ability_update_settings($input = array())
{
    $input = is_array($input) ? $input : array();
    $known = devdadcl_ability_bool_keys();
    $s     = devdadcl_get_settings();
    $touched = 0;
    foreach ($known as $k) {
        if (!array_key_exists($k, $input)) {
            continue;
        }
        $v = devdadcl_ability_bool($input[$k]);
        if (null === $v) {
            /* translators: %s is a setting key. */
            return new WP_Error('devdadcl_invalid_input', sprintf(__('%s must be true or false.', 'devdome-admin-cleaner'), $k));
        }
        $s[$k] = $v ? 1 : 0;
        $touched++;
    }
    if (array_key_exists('client_roles', $input)) {
        $list = $input['client_roles'];
        if (!is_array($list) || count(array_filter($list, 'is_string')) !== count($list)) {
            return new WP_Error('devdadcl_invalid_input', __('client_roles must be a list of role slugs.', 'devdome-admin-cleaner'));
        }
        $allowed = devdadcl_ability_roles();
        $roles   = array();
        $refused = array();
        foreach (array_map('sanitize_key', $list) as $role) {
            if (in_array($role, $allowed, true)) {
                $roles[] = $role;
            } else {
                $refused[] = $role;
            }
        }
        if ($refused) {
            // Never dropped silently as "updated" (DeepSeek round 3): a role with manage_options is exempt by capability.
            /* translators: 1: the refused role slugs, 2: the role slugs Client Mode can apply to. */
            return new WP_Error('devdadcl_invalid_input', sprintf(__('client_roles refused: %1$s. Client Mode can apply to: %2$s.', 'devdome-admin-cleaner'), implode(', ', $refused), implode(', ', $allowed)));
        }
        $s['client_roles'] = array_values(array_unique($roles));
        $touched++;
    }
    if (0 === $touched) {
        return new WP_Error('devdadcl_invalid_input', __('Pass at least one setting to change.', 'devdome-admin-cleaner'));
    }
    // Switching Auto-hide ON hides every future notice for every administrator: the owner's yes first (Codex 2026-09-14).
    $was = devdadcl_get_settings();
    if (!empty($s['auto_hide_new']) && empty($was['auto_hide_new']) && (!isset($input['confirm']) || true !== $input['confirm'])) {
        return new WP_Error('devdadcl_confirm_required', __('Turning Auto-hide on hides every notice that appears from now on, for every administrator. Pass confirm: true after the site owner agreed.', 'devdome-admin-cleaner'));
    }
    if (array_key_exists('client_hide_theme_editor', $s)) {
        $s['client_hide_appearance_editor'] = $s['client_hide_theme_editor']; // the screen keeps the two in step
    }
    $w     = devdadcl_save_settings_with_undo($s, 'update-settings'); // undoable like the screen (DeepSeek round 6)
    $saved = !empty($w['ok']);
    if (function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    $now = devdadcl_ability_settings();
    // Read-back: only what the database holds now counts.
    $not_applied = array();
    foreach ($known as $k) {
        if (array_key_exists($k, $input) && devdadcl_ability_bool($input[$k]) !== $now[$k]) {
            $not_applied[] = $k;
        }
    }
    if (array_key_exists('client_roles', $input)) {
        $want = array_values(array_unique(array_intersect(array_map('sanitize_key', (array) $input['client_roles']), devdadcl_ability_roles())));
        sort($want);
        $have = $now['client_roles'];
        sort($have);
        if ($want !== $have) {
            $not_applied[] = 'client_roles';
        }
    }
    return array('updated' => $saved && empty($not_applied), 'not_applied' => $not_applied, 'settings' => $now);
}

/** Shared by notice-action and delete-notices: validates the hashes, applies one action to each, counts honestly. */
function devdadcl_ability_apply_to_hashes($input, $allowed_actions, $fixed_action = null)
{
    $input  = is_array($input) ? $input : array();
    $hashes = isset($input['hashes']) ? $input['hashes'] : array();
    if (!is_array($hashes) || count(array_filter($hashes, 'is_string')) !== count($hashes) || empty($hashes) || count($hashes) > 200) {
        return new WP_Error('devdadcl_invalid_input', __('hashes must be a list of 1 to 200 notice hashes (from get-notices).', 'devdome-admin-cleaner'));
    }
    $do = null !== $fixed_action ? $fixed_action : (isset($input['action']) ? sanitize_key((string) $input['action']) : '');
    if (!in_array($do, $allowed_actions, true)) {
        /* translators: %s is the comma-separated list of allowed action names. */
        return new WP_Error('devdadcl_invalid_input', sprintf(__('action must be one of: %s.', 'devdome-admin-cleaner'), implode(', ', $allowed_actions)));
    }
    $hashes  = array_values(array_unique(array_map('sanitize_text_field', $hashes)));
    $done    = 0;
    $unknown = array();
    $unchanged = array();
    $failed  = array();
    foreach ($hashes as $hash) {
        $r = devdadcl_notice_apply($hash, $do);
        if (null === $r) {
            $unknown[] = $hash;
        } elseif (true === $r) {
            $done++;
        } elseif (is_wp_error($r)) {
            $failed[] = $hash; // the write did not land: never "unchanged" (DeepSeek round 2)
        } else {
            $unchanged[] = $hash;
        }
    }
    if ($done > 0 && function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    return array('action' => $do, 'done' => $done, 'total' => count($hashes), 'unknown' => $unknown, 'unchanged' => $unchanged, 'failed' => $failed);
}

function devdadcl_ability_notice_action($input = array())
{
    $input = is_array($input) ? $input : array();
    // The same normalisation the apply path uses, BEFORE the confirm gate (DeepSeek round 2: ' HIDE_ADMINS ' passed the
    // gate and was then sanitized to hide_admins).
    $act = isset($input['action']) && is_scalar($input['action']) ? sanitize_key((string) $input['action']) : '';
    if ('hide_admins' === $act && (!isset($input['confirm']) || true !== $input['confirm'])) {
        return new WP_Error('devdadcl_confirm_required', __('hide_admins hides the notice for every administrator. Pass confirm: true after the site owner agreed.', 'devdome-admin-cleaner'));
    }
    return devdadcl_ability_apply_to_hashes($input, array('snooze1', 'snooze7', 'hide', 'hide_admins', 'restore'));
}

function devdadcl_ability_delete_notices($input = array())
{
    $ok = devdadcl_ability_confirmed($input);
    if (is_wp_error($ok)) {
        return $ok;
    }
    return devdadcl_ability_apply_to_hashes($input, array('delete'), 'delete');
}

function devdadcl_ability_clear_all_mutes($input = array())
{
    $ok = devdadcl_ability_confirmed($input, __('This shows every hidden notice again for every administrator (reversible with clean-my-admin or notice-action).', 'devdome-admin-cleaner')); // the screen asks once too
    if (is_wp_error($ok)) {
        return $ok;
    }
    $n = devdadcl_clear_all_mutes();
    if (function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    if (!empty($GLOBALS['devdadcl_unmute_failed'])) {
        /* translators: 1: number of mutes cleared, 2: number of mute rows that stayed. */
        return new WP_Error('devdadcl_write_failed', sprintf(__('%1$d mutes cleared, but %2$d could not be removed; try again.', 'devdome-admin-cleaner'), (int) $n, (int) $GLOBALS['devdadcl_unmute_failed']), array('cleared' => (int) $n));
    }
    if (!empty($GLOBALS['devdadcl_summary_write_failed'])) {
        /* translators: %d is the number of mutes cleared. */
        return new WP_Error('devdadcl_write_failed', sprintf(__('%d mutes cleared, but the summary could not be stored; the counts shown may be stale.', 'devdome-admin-cleaner'), (int) $n), array('cleared' => (int) $n));
    }
    return array('cleared' => (int) $n, 'notices_hidden' => (int) devdadcl_hub_summary()['muted_count']);
}

function devdadcl_ability_delete_notice_data($input = array())
{
    $ok = devdadcl_ability_confirmed($input);
    if (is_wp_error($ok)) {
        return $ok;
    }
    $n = devdadcl_purge_notice_data();
    if (function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    // remaining = records AND mute rows still stored (Codex round 2: a mute that stayed answered remaining: 0).
    $remaining = count(devdadcl_get_inbox()) + count(devdadcl_option_keys('devdadcl_m_'));
    $failed    = isset($GLOBALS['devdadcl_purge_failed']) ? (int) $GLOBALS['devdadcl_purge_failed'] : 0;
    if ($failed > 0 || $remaining > 0) {
        /* translators: 1: number of records deleted, 2: number of rows that stayed. */
        return new WP_Error('devdadcl_write_failed', sprintf(__('%1$d records deleted, but %2$d rows could not be removed; try again.', 'devdome-admin-cleaner'), (int) $n, $remaining), array('deleted_records' => (int) $n, 'remaining' => $remaining));
    }
    return array('deleted_records' => (int) $n, 'remaining' => 0);
}

/** One notice in full: the row plus every mute entry (who hid it, until when) and the classifier's reason. */
function devdadcl_ability_get_notice($input = array())
{
    $input = is_array($input) ? $input : array();
    $hash  = isset($input['hash']) && is_string($input['hash']) ? sanitize_text_field($input['hash']) : '';
    $rec   = '' !== $hash ? devdadcl_get_record($hash) : null;
    if (null === $rec) {
        return new WP_Error('devdadcl_not_found', __('No notice with that hash. Use get-notices to list them.', 'devdome-admin-cleaner'));
    }
    $row = devdadcl_ability_notice_row($hash, $rec, time(), get_current_user_id());
    $m   = devdadcl_mute_get($hash);
    $row['mutes'] = array(
        'admins_until' => null === $m ? null : $m['admins'],
        'users'        => null === $m ? array() : array_map('intval', (array) $m['users']),
        'set_by'       => null === $m ? 0 : (int) $m['actor'],
        'set_at'       => null === $m ? 0 : (int) $m['ts'],
    );
    $row['classifier'] = array('state' => (string) $rec['state'], 'confidence' => (float) $rec['confidence'], 'reason' => (string) $rec['reason'], 'policy_version' => (int) $rec['policy_version']);
    $row['resolved']   = !empty($rec['resolved']);
    return $row;
}

/** Snooze one or more notices for N days (1 to 90), for this user or for every administrator. */
function devdadcl_ability_snooze_notices($input = array())
{
    $input = is_array($input) ? $input : array();
    $days  = isset($input['days']) ? (int) $input['days'] : 0;
    $scope = isset($input['scope']) ? sanitize_key((string) $input['scope']) : 'user';
    if ($days < 1 || $days > 90) {
        return new WP_Error('devdadcl_invalid_input', __('days must be between 1 and 90.', 'devdome-admin-cleaner'));
    }
    if (!in_array($scope, array('user', 'admins'), true)) {
        return new WP_Error('devdadcl_invalid_input', __('scope must be user or admins.', 'devdome-admin-cleaner'));
    }
    if ('admins' === $scope && (!isset($input['confirm']) || true !== $input['confirm'])) {
        return new WP_Error('devdadcl_confirm_required', __('Snoozing for every administrator needs confirm: true after the site owner agreed.', 'devdome-admin-cleaner'));
    }
    $hashes = isset($input['hashes']) ? $input['hashes'] : array();
    if (!is_array($hashes) || count(array_filter($hashes, 'is_string')) !== count($hashes) || empty($hashes) || count($hashes) > 200) {
        return new WP_Error('devdadcl_invalid_input', __('hashes must be a list of 1 to 200 notice hashes (from get-notices).', 'devdome-admin-cleaner'));
    }
    $until = time() + $days * DAY_IN_SECONDS;
    $hashes = array_values(array_unique(array_map('sanitize_text_field', $hashes))); // total counts what is processed (DeepSeek round 4)
    $done = 0; $unknown = array(); $unchanged = array(); $failed = array();
    foreach ($hashes as $hash) {
        if (devdadcl_get_record($hash) === null) {
            $unknown[] = $hash;
        } elseif ('admins' === $scope ? devdadcl_mute_active_for_admins($hash) : devdadcl_mute_active_for_user($hash)) {
            $unchanged[] = $hash; // already hidden in that scope (DeepSeek round 5: a personal mute must not block the all-admins one)
        } elseif (devdadcl_mute_set($hash, $until, $scope)) {
            $done++;
        } elseif ('admins' === $scope ? devdadcl_mute_active_for_admins($hash) : devdadcl_mute_active_for_user($hash)) {
            $unchanged[] = $hash;
        } else {
            $failed[] = $hash; // the write did not land (DeepSeek round 2)
        }
    }
    if ($done > 0 && function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    return array('action' => 'snooze', 'days' => $days, 'scope' => $scope, 'until' => $until, 'done' => $done, 'total' => count($hashes), 'unknown' => $unknown, 'unchanged' => $unchanged, 'failed' => $failed);
}

/** What the daily job does, now: expired snoozes and orphan mutes cleared, the record cap enforced, the summary recomputed. */
function devdadcl_ability_run_housekeeping($input = array())
{
    $ok = devdadcl_ability_confirmed($input); // retention evicts records for good (Codex + DeepSeek round 2)
    if (is_wp_error($ok)) {
        return $ok;
    }
    $gc      = (int) devdadcl_gc();
    $evicted = (int) devdadcl_enforce_retention();
    devdadcl_refresh_summary();
    return array('mutes_cleaned' => $gc, 'records_evicted' => $evicted, 'status' => devdadcl_ability_get_status());
}

function devdadcl_ability_refresh_summary($input = array())
{
    devdadcl_refresh_summary();
    if (!empty($GLOBALS['devdadcl_summary_write_failed'])) {
        return new WP_Error('devdadcl_write_failed', __('The summary was recomputed but could not be stored: the database did not keep it. The previous numbers stand.', 'devdome-admin-cleaner'));
    }
    return devdadcl_ability_get_status();
}

/* ------------------------------ registration ------------------------------ */

add_action('wp_abilities_api_init', 'devdadcl_register_abilities');
function devdadcl_register_abilities()
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    $empty   = array('type' => 'object', 'properties' => array(), 'additionalProperties' => false);
    $bool    = function ($desc) { return array('type' => 'boolean', 'description' => $desc); };
    $confirm = array('confirm' => array('type' => 'boolean', 'description' => 'Must be true. Ask the user before passing it: this permanently deletes stored notice records.'));
    $hashes  = array('hashes' => array('type' => 'array', 'items' => array('type' => 'string'), 'minItems' => 1, 'maxItems' => 200, 'description' => 'Notice hashes from get-notices (at most 200 per call).'));

    $settings_props = array();
    foreach (devdadcl_ability_bool_keys() as $k) {
        $settings_props[$k] = $bool('');
    }
    $settings_props['auto_hide_new']['description']  = 'Hide every notice that appears for the first time, for all administrators, the moment it shows up.';
    $settings_props['client_mode_on']['description'] = 'Client Mode master switch (cosmetic: hides menu entries for the client roles, changes no permission).';
    $settings_props['show_bell']['description']      = 'Show the admin-bar bell with the count of hidden notices.';
    $settings_props['hide_plugin_widgets']['description']   = 'Remove every dashboard widget a plugin or theme added.';
    $settings_props['hide_plugin_row_notices']['description'] = 'Drop the notice rows plugins print under their entry on the Plugins list.';
    $settings_props['hide_plugin_bar_items']['description'] = 'Remove every top-level admin-bar item a plugin or theme added.';
    $settings_props['mute_promos_on_cleanup']['description'] = 'Clean My Admin also hides every notice showing at that moment.';
    $settings_props['client_roles'] = array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Role slugs Client Mode applies to (only roles registered on this site are kept).');
    $settings_out = array('type' => 'object', 'properties' => array_merge($settings_props, array('cleanup_applied' => $bool('true once Clean My Admin has run.'))));

    $notice_row = array('type' => 'object', 'properties' => array(
        'hash' => array('type' => 'string'), 'type' => array('type' => 'string', 'enum' => array('critical', 'promo', 'normal')),
        'status' => array('type' => 'string', 'enum' => array('visible', 'hidden', 'snoozed')), 'scope' => array('type' => 'string', 'enum' => array('', 'admins', 'user')),
        'until' => array('type' => 'integer', 'description' => 'Unix time a snooze ends, 0 = hidden until restored.'), 'first_seen' => array('type' => 'integer'), 'last_seen' => array('type' => 'integer'),
        'source' => array('type' => 'string'), 'excerpt' => array('type' => 'string', 'description' => 'Redacted text of the notice.'),
    ));
    $status_out = array('type' => 'object', 'properties' => array(
        'cleanup_applied' => $bool(''), 'auto_hide_new' => $bool(''), 'client_mode_on' => $bool(''), 'notices_seen' => array('type' => 'integer'),
        'critical_visible' => array('type' => 'integer'), 'critical_hidden' => array('type' => 'integer'), 'promos_visible' => array('type' => 'integer'), 'notices_hidden' => array('type' => 'integer'),
        'hidden_widgets' => array('type' => 'integer'), 'hidden_bar_items' => array('type' => 'integer'), 'cleanliness' => array('type' => array('integer', 'null')), 'summary_updated_at' => array('type' => 'integer'),
        'undo_available' => $bool(''), 'undo_label' => array('type' => 'string'), 'storage_warning' => $bool(''), 'plugin_version' => array('type' => 'string'),
    ));
    $apply_out = array('type' => 'object', 'properties' => array(
        'action' => array('type' => 'string'), 'done' => array('type' => 'integer'), 'total' => array('type' => 'integer'),
        'unknown' => array('type' => 'array', 'items' => array('type' => 'string')), 'unchanged' => array('type' => 'array', 'items' => array('type' => 'string')),
        'failed' => array('type' => 'array', 'items' => array('type' => 'string')),
    ));

    // Every answer passes the guard (DESIGN.md 24: a failed query = a database error, never "done") and the sanitizer.
    $guarded = function ($cb) {
        return function ($input = array()) use ($cb) {
            devdadcl_db_guard_begin();
            try {
                $r = call_user_func($cb, $input);
                if (devdadcl_db_guard_active()) {
                    // A failed query in this window is the answer, even when the callback produced its own error text
                    // (Codex round 5: "Nothing was changed" after a deadlock that had also broken the rollback).
                    $r = new WP_Error('devdadcl_db_error', devdadcl_db_guard_message());
                }
            } finally {
                devdadcl_db_guard_end();
            }
            return devdadcl_ability_strip($r);
        };
    };
    $reg = function ($id, $label, $desc, $in, $out, $cb, $kind, $idempotent = null) use ($guarded) {
        wp_register_ability($id, array(
            'label'               => $label,
            'description'         => $desc,
            'category'            => 'devdome-admin-cleaner',
            'input_schema'        => $in,
            'output_schema'       => $out,
            'execute_callback'    => $guarded($cb),
            'permission_callback' => 'devdadcl_ability_can',
            'meta'                => devdadcl_ability_meta($kind, $idempotent),
        ));
    };

    $reg('devdome-admin-cleaner/get-status', __('Get Admin Cleaner status', 'devdome-admin-cleaner'),
        __('The Overview numbers: cleanliness score, notices seen in 30 days, critical visible / hidden, promos visible, notices hidden, hidden widgets and admin-bar items, whether Clean My Admin ran, auto-hide and Client Mode switches, whether Undo is available. Read-only.', 'devdome-admin-cleaner'),
        $empty, $status_out, 'devdadcl_ability_get_status', 'read');
    $reg('devdome-admin-cleaner/get-settings', __('Get Admin Cleaner settings', 'devdome-admin-cleaner'),
        __('Every setting as a boolean (which dashboard widgets, admin-bar items and footer lines are hidden, the bell, auto-hide, Client Mode and what it hides) plus the client roles and the roles registered on this site. Read-only.', 'devdome-admin-cleaner'),
        $empty, array('type' => 'object', 'properties' => array('settings' => $settings_out, 'roles_available' => array('type' => 'array', 'items' => array('type' => 'string')))), 'devdadcl_ability_get_settings', 'read');
    $reg('devdome-admin-cleaner/get-notices', __('List captured admin notices', 'devdome-admin-cleaner'),
        __('The Notice Inbox: every admin notice the plugin has seen, with type (critical / promo / normal), status (visible / hidden / snoozed) and scope, first and last seen, source and redacted text. Filter by status, type or a search word; newest first; paged. Read-only.', 'devdome-admin-cleaner'),
        array('type' => 'object', 'properties' => array(
            'status' => array('type' => 'string', 'enum' => array('all', 'visible', 'hidden', 'snoozed'), 'default' => 'all'),
            'type'   => array('type' => 'string', 'enum' => array('all', 'critical', 'promo', 'normal'), 'default' => 'all'),
            'search' => array('type' => 'string', 'description' => 'Case-insensitive match on the text or the source.'),
            'limit'  => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50),
            'offset' => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
            'sort'   => array('type' => 'string', 'enum' => array('last_seen', 'first_seen', 'type', 'status'), 'default' => 'last_seen', 'description' => 'The same columns the screen sorts by.'),
            'dir'    => array('type' => 'string', 'enum' => array('asc', 'desc'), 'default' => 'desc'),
        ), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('total' => array('type' => 'integer'), 'offset' => array('type' => 'integer'), 'limit' => array('type' => 'integer'), 'notices' => array('type' => 'array', 'items' => $notice_row))),
        'devdadcl_ability_get_notices', 'read');
    $reg('devdome-admin-cleaner/clean-my-admin', __('Clean My Admin (one click)', 'devdome-admin-cleaner'),
        __('The one-click cleanup: hides every dashboard widget, every extra admin-bar item, the footer text and version, switches auto-hide on, and hides every admin notice showing right now for all administrators. Takes a snapshot first so undo-last-change can reverse the settings. Reversible: nothing is deleted.', 'devdome-admin-cleaner'),
        array('type' => 'object', 'properties' => array('confirm' => array('type' => 'boolean', 'description' => 'Must be true: this hides every widget, toolbar item and notice for every administrator. Ask the site owner first.')), 'required' => array('confirm'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('cleaned' => $bool(''), 'notices_hidden' => array('type' => 'integer'), 'status' => $status_out)),
        'devdadcl_ability_clean_my_admin', 'modify', false);
    $reg('devdome-admin-cleaner/undo-last-change', __('Undo the last settings change', 'devdome-admin-cleaner'),
        __('Restores the settings exactly as they were before the last settings change (Clean My Admin, a Save on the screen or update-settings; one level). Needs confirm: true when the restored settings turn Auto-hide new notices on. Notices hidden by a cleanup stay hidden; restore them with notice-action or clear-all-mutes.', 'devdome-admin-cleaner'),
        array('type' => 'object', 'properties' => array('confirm' => array('type' => 'boolean', 'description' => __('Needed only when the restored settings turn Auto-hide new notices on for every administrator; nothing is deleted.', 'devdome-admin-cleaner'))), 'additionalProperties' => false), array('type' => 'object', 'properties' => array('undone' => $bool(''), 'label' => array('type' => 'string'), 'settings' => $settings_out)),
        'devdadcl_ability_undo_last_change', 'modify', false);
    $reg('devdome-admin-cleaner/update-settings', __('Update Admin Cleaner settings', 'devdome-admin-cleaner'),
        __('Change any settings; only the keys you pass change, through the same save the screen uses. Every value is read back before updated: true is returned; keys that could not be applied are listed in not_applied.', 'devdome-admin-cleaner'),
        array('type' => 'object', 'properties' => array_merge($settings_props, array('confirm' => array('type' => 'boolean', 'description' => 'Required (true) only when auto_hide_new goes from off to on: that hides every future notice for every administrator.'))), 'additionalProperties' => false), array('type' => 'object', 'properties' => array('updated' => $bool(''), 'not_applied' => array('type' => 'array', 'items' => array('type' => 'string')), 'settings' => $settings_out)),
        'devdadcl_ability_update_settings', 'modify');
    $reg('devdome-admin-cleaner/notice-action', __('Snooze, hide or restore notices', 'devdome-admin-cleaner'),
        __('Apply one action to one or more notices: snooze1 (this user, 1 day), snooze7 (this user, 7 days), hide (this user, until restored), hide_admins (every administrator, until restored), restore (visible again for everyone). Unknown hashes are reported, never guessed.', 'devdome-admin-cleaner'),
        array('type' => 'object', 'properties' => array_merge($hashes, array('action' => array('type' => 'string', 'enum' => array('snooze1', 'snooze7', 'hide', 'hide_admins', 'restore')), 'confirm' => array('type' => 'boolean', 'description' => 'Required (true) for hide_admins: it hides the notice for every administrator.'))), 'required' => array('hashes', 'action'), 'additionalProperties' => false),
        $apply_out, 'devdadcl_ability_notice_action', 'modify', false);
    $reg('devdome-admin-cleaner/delete-notices', __('Delete notice records', 'devdome-admin-cleaner'),
        __('Forget one or more notices entirely: the stored record and its mute are removed. If the source plugin still emits the notice it comes back as a fresh row (hidden at once while Auto-hide is on, visible otherwise). Destructive; requires confirm: true.', 'devdome-admin-cleaner'),
        array('type' => 'object', 'properties' => array_merge($hashes, $confirm), 'required' => array('hashes', 'confirm'), 'additionalProperties' => false),
        $apply_out, 'devdadcl_ability_delete_notices', 'destroy');
    $reg('devdome-admin-cleaner/clear-all-mutes', __('Show every hidden notice again', 'devdome-admin-cleaner'),
        __('Needs confirm: true. Clears every mute and snooze (all scopes, all users) so every hidden notice is visible again for every administrator. The records stay in the inbox.', 'devdome-admin-cleaner'),
        array('type' => 'object', 'properties' => array('confirm' => array('type' => 'boolean', 'description' => __('Must be true: every hidden or snoozed notice becomes visible again for every administrator.', 'devdome-admin-cleaner'))), 'required' => array('confirm'), 'additionalProperties' => false), array('type' => 'object', 'properties' => array('cleared' => array('type' => 'integer'), 'notices_hidden' => array('type' => 'integer'))),
        'devdadcl_ability_clear_all_mutes', 'modify');
    $reg('devdome-admin-cleaner/delete-notice-data', __('Delete all notice data', 'devdome-admin-cleaner'),
        __('The privacy purge: removes every stored notice record and every mute from this site. Settings are not touched. Destructive; requires confirm: true.', 'devdome-admin-cleaner'),
        array('type' => 'object', 'properties' => $confirm, 'required' => array('confirm'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('deleted_records' => array('type' => 'integer'), 'remaining' => array('type' => 'integer'))),
        'devdadcl_ability_delete_notice_data', 'destroy');
    $reg('devdome-admin-cleaner/refresh-summary', __('Recompute the Overview numbers', 'devdome-admin-cleaner'),
        __('Recomputes the cached summary (score and counts) from the current data and returns it.', 'devdome-admin-cleaner'),
        $empty, $status_out, 'devdadcl_ability_refresh_summary', 'modify');
    $reg('devdome-admin-cleaner/get-notice', __('Get one notice in full', 'devdome-admin-cleaner'),
        __('One notice by hash: type, status, scope, the redacted text and source, first and last seen, every mute entry (who hid it, until when), and the classifier verdict with its reason. Read-only.', 'devdome-admin-cleaner'),
        array('type' => 'object', 'properties' => array('hash' => array('type' => 'string')), 'required' => array('hash'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array_merge($notice_row['properties'], array('mutes' => array('type' => 'object'), 'classifier' => array('type' => 'object'), 'resolved' => $bool('')))),
        'devdadcl_ability_get_notice', 'read');
    $reg('devdome-admin-cleaner/snooze-notices', __('Snooze notices for N days', 'devdome-admin-cleaner'),
        __('Hide one or more notices for 1 to 90 days, for this user (scope user) or for every administrator (scope admins, needs confirm: true). The screen offers 1 and 7 days; this is the general form.', 'devdome-admin-cleaner'),
        array('type' => 'object', 'properties' => array_merge($hashes, array('days' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 90), 'scope' => array('type' => 'string', 'enum' => array('user', 'admins'), 'default' => 'user'), 'confirm' => array('type' => 'boolean', 'description' => 'Required (true) for scope admins.'))), 'required' => array('hashes', 'days'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array_merge($apply_out['properties'], array('days' => array('type' => 'integer'), 'scope' => array('type' => 'string'), 'until' => array('type' => 'integer')))),
        'devdadcl_ability_snooze_notices', 'modify', false);
    $reg('devdome-admin-cleaner/run-housekeeping', __('Run the daily housekeeping now', 'devdome-admin-cleaner'),
        __('Needs confirm: true. Clears expired snoozes and orphaned mutes, enforces the 200-record cap by deleting records FOR GOOD (resolved ones first, then the oldest non-critical ones; unresolved critical records are never evicted) and recomputes the summary. The same job the daily schedule runs.', 'devdome-admin-cleaner'),
        array('type' => 'object', 'properties' => array('confirm' => array('type' => 'boolean', 'description' => __('Must be true: records over the cap (resolved first, then the oldest non-critical) are deleted for good.', 'devdome-admin-cleaner'))), 'required' => array('confirm'), 'additionalProperties' => false), array('type' => 'object', 'properties' => array('mutes_cleaned' => array('type' => 'integer'), 'records_evicted' => array('type' => 'integer'), 'status' => $status_out)),
        'devdadcl_ability_run_housekeeping', 'destroy');
}

/**
 * MCP Adapter: list every Admin Cleaner ability as a tool on the default server (next to its discover / execute
 * meta-tools). Harmless when the adapter is not installed.
 */
add_filter('mcp_adapter_default_server_config', 'devdadcl_mcp_default_server_tools');
function devdadcl_mcp_default_server_tools($config)
{
    if (!is_array($config)) {
        return $config;
    }
    $tools = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : array();
    $config['tools'] = array_values(array_unique(array_merge($tools, devdadcl_ability_ids())));
    return $config;
}
