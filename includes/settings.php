<?php
/**
 * Settings storage — a SINGLE WordPress option array 'devdadcl_settings' (autoload yes),
 * read/merged against seeded defaults so a missing key always returns its default. No custom
 * tables.
 */

defined('ABSPATH') || exit;

/** Option name for the single settings array. */
function devdadcl_settings_option()
{
    return 'devdadcl_settings';
}

/**
 * Full set of default settings. Every persisted key is listed here so reads never miss a key
 * and saves can rely on a stable shape.
 */
function devdadcl_default_settings()
{
    return array(
        // --- one-click / lifecycle flags ---
        'cleanup_applied'      => 0,   // 1 once the Recommended cleanup has been applied
        'auto_hide_new'        => 0,   // 1 = every notice captured for the first time is hidden for all admins at once (owner 2026-09-14)
        'client_mode_on'       => 0,   // master switch for Client Mode

        // --- dashboard widget removal (1 = hide) ---
        'hide_welcome_panel'   => 0,
        'hide_at_a_glance'     => 0,
        'hide_activity'        => 0,
        'hide_quick_draft'     => 0,
        'hide_primary_news'    => 0,   // WordPress Events & News
        'hide_site_health'     => 0,
        'hide_plugin_widgets'  => 0,   // every dashboard widget a plugin or theme added (not the six core ones above)

        // --- admin bar node removal (1 = hide) ---
        'hide_ab_wp_logo'      => 0,
        'hide_ab_comments'     => 0,
        'hide_ab_new_content'  => 0,
        'hide_ab_updates'      => 0,
        'hide_ab_customize'    => 0,
        'hide_plugin_bar_items' => 0,  // every top-level admin-bar item a plugin or theme added

        // --- footer / version ---
        'hide_footer_text'     => 0,
        'hide_footer_version'  => 0,
        'hide_plugin_row_notices' => 0, // the notice rows plugins print under their entry on the Plugins list ("Upgrade to Pro", update rows)

        // --- admin bar "bell" inbox indicator ---
        'show_bell'            => 1,

        // --- notices ---
        'mute_promos_on_cleanup' => 1, // one-click hides every notice the inbox knows, for all admins (owner hide-all rule)

        // --- client mode ---
        'client_roles'         => array('editor', 'author'), // roles treated as "clients"
        'client_hide_plugins'  => 1,
        'client_hide_tools'    => 1,
        'client_hide_settings' => 1,
        'client_hide_appearance_editor' => 1,
        'client_hide_theme_editor'  => 1,
        'client_hide_plugin_editor' => 1,
        'client_hide_updates'  => 1,
        'client_hide_version'  => 1,
    );
}

/**
 * Read the whole settings array, merged onto defaults (every key always present).
 *
 * Memoized per request: the hide engine, footer filters and Client Mode each read settings several
 * times per page (front AND back, since the admin bar renders on the front end too). The single
 * option is autoloaded (served from WP's cache, no repeat query), but the defaults merge is not free
 * — memoizing collapses the many merges per request down to one. Pass $flush=true to drop the memo
 * after a save so later reads in the same request see the new values.
 */
function devdadcl_get_settings($flush = false)
{
    static $cache = null;
    if ($flush) {
        $cache = null; // fall through: a flush answers the FRESH settings, never an empty array (DeepSeek round 8)
    }
    if (is_array($cache)) {
        return $cache;
    }
    $stored = get_option(devdadcl_settings_option(), array());
    if (!is_array($stored)) {
        $stored = array();
    }
    $cache = array_merge(devdadcl_default_settings(), $stored);
    return $cache;
}

/** Read one setting, falling back to its default (then $fallback if not a known key). */
function devdadcl_get($name, $fallback = null)
{
    $s = devdadcl_get_settings();
    if (array_key_exists($name, $s)) {
        return $s[$name];
    }
    return $fallback;
}

/** Read an integer setting. */
function devdadcl_get_int($name, $fallback = 0)
{
    return (int) devdadcl_get($name, $fallback);
}

/** Read an array setting, always returning an array. */
function devdadcl_get_array($name, $fallback = array())
{
    $v = devdadcl_get($name, $fallback);
    return is_array($v) ? $v : (array) $fallback;
}

/**
 * Persist the WHOLE settings array (caller passes a complete, sanitized array).
 * @return bool true only when the row holds the saved array afterwards (DESIGN.md 24.5); callers say "not saved" on false.
 */
function devdadcl_save_settings($settings)
{
    if (!is_array($settings)) {
        return false;
    }
    // Keep only known keys, merged onto defaults so the option shape stays stable.
    $clean = array_merge(devdadcl_default_settings(), array_intersect_key($settings, devdadcl_default_settings()));
    foreach (devdadcl_default_settings() as $k => $d) { // every value in its default's type: 'foo' never lands as a flag (DeepSeek round 8)
        $clean[$k] = is_array($d) ? array_values(array_filter((array) $clean[$k], 'is_string')) : (int) !empty($clean[$k]);
    }
    $ok    = devdadcl_option_write(devdadcl_settings_option(), $clean);
    devdadcl_get_settings(true); // bust the per-request memo so later reads see the saved values
    return $ok;
}

/**
 * The "Recommended" one-click preset (a partial settings array). Used by the One-Click Cleanup
 * button. Deliberately conservative: hides obvious clutter + version, declutters the admin bar,
 * and (separately) hides every notice the inbox knows for all admins (owner hide-all rule, 2026-09-14).
 */
function devdadcl_recommended_preset()
{
    // Owner 2026-09-14: one click = EVERYTHING gone. Whoever needs a widget, a bar item or a notice back
    // switches it back on (Dashboard Widgets / Admin Bar tabs) or restores it from the Notice Inbox.
    return array(
        'hide_welcome_panel'  => 1,
        'hide_primary_news'   => 1,
        'hide_activity'       => 1,
        'hide_quick_draft'    => 1,
        'hide_at_a_glance'    => 1,
        'hide_site_health'    => 1,
        'hide_plugin_widgets' => 1,
        'hide_ab_wp_logo'     => 1,
        'hide_ab_comments'    => 1,
        'hide_ab_new_content' => 1,
        'hide_ab_updates'     => 1,
        'hide_ab_customize'   => 1,
        'hide_plugin_bar_items' => 1,
        'hide_footer_text'    => 1,
        'hide_footer_version' => 1,
        'hide_plugin_row_notices' => 1,
        'mute_promos_on_cleanup' => 1,
        'auto_hide_new'       => 1, // the first click also switches auto-hide on: tomorrow's promo never shows
    );
}

/* ----------------------------- undo snapshot ----------------------------- */

/**
 * Bounded pre-change snapshot: the FULL settings array as it was before the most recent
 * settings-changing operation (one level — "Undo last change", not a history). Written by
 * one-click, immediately before it changes anything.
 */
function devdadcl_snapshot_settings($label, $settings = null)
{
    return devdadcl_option_write('devdadcl_last_change', array(
        'ts'       => time(),
        'actor'    => get_current_user_id(),
        'label'    => (string) $label,
        'settings' => is_array($settings) ? $settings : devdadcl_get_settings(),
    )); // proved (DESIGN.md 24.5): an Undo that would restore nothing is refused by the caller
}

/** The stored last-change snapshot, or null. */
function devdadcl_last_change()
{
    $v = get_option('devdadcl_last_change', null);
    return (is_array($v) && isset($v['settings']) && is_array($v['settings'])) ? $v : null;
}
