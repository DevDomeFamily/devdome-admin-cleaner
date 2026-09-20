<?php
/**
 * Activation / deactivation lifecycle. Seeds the single settings option with defaults (only when
 * missing — reactivation never wipes a configured site), seeds an empty hub summary, and schedules
 * the daily summary-refresh cron. Deactivation is non-destructive: it only clears schedules.
 * Full removal lives in uninstall.php.
 */

defined('ABSPATH') || exit;

function devdadcl_activate()
{
    // Seed settings only when missing so an existing configuration is never clobbered.
    if (get_option(devdadcl_settings_option(), null) === null) {
        add_option(devdadcl_settings_option(), devdadcl_default_settings(), '', 'yes');
    }

    // Fresh installs start on the current storage schema (per-record rows; there is no
    // whole-option inbox to seed any more). Existing schema-1 sites migrate on admin_init.
    if (get_option('devdadcl_schema', null) === null) {
        add_option('devdadcl_schema', DEVDADCL_SCHEMA_VERSION, '', 'no');
    }

    // Seed an empty cached hub summary so tiles/health never query on render.
    if (get_option(devdadcl_summary_option(), null) === null) {
        add_option(devdadcl_summary_option(), devdadcl_empty_summary(), '', 'no');
    }

    // Daily summary refresh (recomputes the cached hub snapshot).
    if (!wp_next_scheduled('devdadcl_summary_refresh')) {
        wp_schedule_event(time() + 3600, 'daily', 'devdadcl_summary_refresh');
    }
}

/**
 * Activation writes are bare add_option()/wp_schedule_event() calls with no proof (DeepSeek round 2). Settings and the
 * summary self-heal at read time (defaults merge in); the daily refresh does not, so a missing schedule is re-created
 * on the next admin load.
 */
function devdadcl_heal_schedule()
{
    if (!wp_next_scheduled('devdadcl_summary_refresh')) {
        wp_schedule_event(time() + 3600, 'daily', 'devdadcl_summary_refresh');
    }
}
add_action('admin_init', 'devdadcl_heal_schedule', 98);

function devdadcl_deactivate()
{
    // Non-destructive: settings + inbox survive a toggle. Only clear schedules.
    wp_clear_scheduled_hook('devdadcl_summary_refresh');
}
