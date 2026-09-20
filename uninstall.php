<?php
/**
 * Full data removal — runs only when the plugin is DELETED (not on deactivate).
 * Deletes the plugin options (settings, notice inbox, mute map, cached hub summary), clears the
 * scheduled refresh hook, loops multisite, and coordinates shared-core cleanup. Non-destructive to
 * the rest of the site: this plugin never wrote outside its own options.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/** Per-site cleanup (called once per site on multisite, or once on single-site). */
function devdadcl_uninstall_site()
{
    global $wpdb;

    delete_option('devdadcl_settings');
    delete_option('devdadcl_notices'); // legacy schema-1 stores
    delete_option('devdadcl_muted');
    delete_option('devdadcl_schema');
    delete_option('devdadcl_storage_warning');
    delete_option('devdadcl_last_change');
    delete_option('devdadcl_hub_summary');

    // Schema-2 per-record rows: one option per notice (devdadcl_n_<hash>) and per mute
    // (devdadcl_m_<hash>). Escaped underscores so LIKE cannot match other plugins' rows.
    // (The pre-0.2.0 generic summary row is dropped by includes/migrate.php on upgrade.)
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('devdadcl_n_') . '%', $wpdb->esc_like('devdadcl_m_') . '%')); // the rows are devdadcl_n_/devdadcl_m_ (wp.org prefix rule: no other prefix is ever named here)

    wp_clear_scheduled_hook('devdadcl_summary_refresh');
}

if (is_multisite()) {
    $devdadcl_sites = get_sites(array('fields' => 'ids', 'number' => 0));
    foreach ($devdadcl_sites as $devdadcl_blog_id) {
        switch_to_blog((int) $devdadcl_blog_id);
        devdadcl_uninstall_site();
        restore_current_blog();
    }
} else {
    devdadcl_uninstall_site();
}

// Coordinate shared-core cleanup (removes the core cron/option only if this is the LAST DevDome
// plugin still installed, so removing this one never orphans the core for the others).
require_once __DIR__ . '/lib/devdome-core/uninstall.php';
devdcorev1_uninstall_cleanup('devdome-admin-cleaner/devdome-admin-cleaner.php');

wp_cache_flush();
