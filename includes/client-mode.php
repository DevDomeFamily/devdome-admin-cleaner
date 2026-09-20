<?php
/**
 * Client Mode — COSMETIC workspace simplification for users in the targeted "client" roles:
 * hides selected admin menu entries, redirects the three documented screens
 * (theme-editor.php, plugin-editor.php, update-core.php) back to the dashboard, and hides
 * the WordPress version. Applies only when the master switch is on, the user holds a
 * targeted role, and the user does NOT hold a privileged capability (manage_options, the
 * filtered Admin Cleaner capability, or network admin — capability beats role slug).
 *
 * WHAT THIS IS NOT (audit batch 5): a security boundary. It never changes capabilities.
 * Menus are hidden, not access-controlled: a client-role user who types a URL reaches any
 * screen their CAPABILITIES allow, exactly as WordPress decides — only the three screens
 * above are redirected, and even that is cosmetic (the capability is unchanged). The UI
 * copy states the same thing; claims like "stops clients breaking the site" were removed.
 */

defined('ABSPATH') || exit;

/** Should Client Mode apply to the current user right now? */
function devdadcl_client_mode_active()
{
    if (!is_admin() || !devdadcl_get_int('client_mode_on', 0)) {
        return false;
    }
    if (!is_user_logged_in()) {
        return false;
    }
    $user = wp_get_current_user();
    if (!$user || empty($user->roles)) {
        return false;
    }
    // CAPABILITY-based exemption, not a role-slug check (audit batch 5): a user holding
    // manage_options — or the filtered Admin Cleaner capability, or network admin — is
    // never restricted, whatever their role slugs say. Multi-role precedence: the
    // privileged capability wins. The old check only looked for the literal role
    // 'administrator', so a user with editor + a custom manage_options role was
    // restricted anyway.
    if (current_user_can('manage_options') || current_user_can(devdadcl_capability()) || current_user_can('manage_network')) {
        return false;
    }
    $targets = devdadcl_get_array('client_roles', array('editor', 'author'));
    foreach ((array) $user->roles as $role) {
        if (in_array($role, $targets, true)) {
            return true;
        }
    }
    return false;
}

/** Remove targeted top-level / submenu items for client users (late so plugin menus exist). */
function devdadcl_client_hide_menus()
{
    if (!devdadcl_client_mode_active()) {
        return;
    }
    $s = devdadcl_get_settings();

    if (!empty($s['client_hide_plugins'])) {
        remove_menu_page('plugins.php');
    }
    if (!empty($s['client_hide_tools'])) {
        remove_menu_page('tools.php');
    }
    if (!empty($s['client_hide_settings'])) {
        remove_menu_page('options-general.php');
    }
    if (!empty($s['client_hide_updates'])) {
        // Updates live under Dashboard.
        remove_submenu_page('index.php', 'update-core.php');
    }
    // Both "Appearance editor" and "Theme editor" settings target the same themes.php > theme-editor.php
    // submenu, so a single OR-guarded removal covers either (matches the OR logic in the URL guard below).
    if (!empty($s['client_hide_appearance_editor']) || !empty($s['client_hide_theme_editor'])) {
        remove_submenu_page('themes.php', 'theme-editor.php');
    }
    if (!empty($s['client_hide_plugin_editor'])) {
        remove_submenu_page('plugins.php', 'plugin-editor.php');
    }
}
add_action('admin_menu', 'devdadcl_client_hide_menus', 999);

/**
 * Belt-and-braces: block direct loads of the theme/plugin file editors for client users (removing
 * the menu item alone doesn't block a typed-in URL). Cosmetic guard only — capabilities unchanged.
 */
function devdadcl_client_guard_editors()
{
    if (!devdadcl_client_mode_active()) {
        return;
    }
    global $pagenow;
    $s = devdadcl_get_settings();
    $blocked = false;

    if ($pagenow === 'theme-editor.php' && (!empty($s['client_hide_theme_editor']) || !empty($s['client_hide_appearance_editor']))) {
        $blocked = true;
    }
    if ($pagenow === 'plugin-editor.php' && !empty($s['client_hide_plugin_editor'])) {
        $blocked = true;
    }
    if ($pagenow === 'update-core.php' && !empty($s['client_hide_updates'])) {
        $blocked = true;
    }

    if ($blocked) {
        wp_safe_redirect(admin_url());
        exit;
    }
}
add_action('admin_init', 'devdadcl_client_guard_editors');

/** Hide the WordPress version in the footer for client users. */
function devdadcl_client_hide_version($version)
{
    if (devdadcl_client_mode_active() && devdadcl_get_int('client_hide_version', 1)) {
        return '';
    }
    return $version;
}
add_filter('update_footer', 'devdadcl_client_hide_version', 1000);
