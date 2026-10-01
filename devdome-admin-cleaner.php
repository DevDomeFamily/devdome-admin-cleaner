<?php
/*
Plugin Name: DevDome Admin Cleaner
Plugin URI: https://devdome.com/
Description: Clean up your WordPress admin in one click. Hide promo notices, remove dashboard clutter, simplify the admin bar, and give client roles a simpler workspace. Part of the DevDome suite.
Version: 1.0.9
Author: DevDome
Author URI: https://devdome.com
Text Domain: devdome-admin-cleaner
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) {
    exit;
}

// The WordPress.org zip ships this marker file (defines DEVDCOREV1_WPORG_BUILD) so the same codebase
// can switch off self-hosted updates and the suite phone-home for a wp.org-compliant build.
if (file_exists(__DIR__ . '/wporg-build.php')) {
    require __DIR__ . '/wporg-build.php';
}

define('DEVDADCL_VERSION', '1.0.9');
define('DEVDADCL_DIR', plugin_dir_path(__FILE__));
define('DEVDADCL_URL', plugin_dir_url(__FILE__));
define('DEVDADCL_FILE', __FILE__);
define('DEVDADCL_PAGE', 'devdome-admin-cleaner');

// Shared DevDome core (vendored, version-guarded; only the highest copy across all installed
// DevDome plugins actually loads). Provides the suite hub, account/licensing seam, report
// contract and shared cron. Loaded FIRST, above every other require.
require_once DEVDADCL_DIR . 'lib/devdome-core/loader.php';

require_once DEVDADCL_DIR . 'includes/db-guard.php'; // DESIGN.md 24 / 24.5: failed-query guard + proved option writes
require_once DEVDADCL_DIR . 'includes/settings.php';
require_once DEVDADCL_DIR . 'includes/install.php';

// Request-wide database guard (DESIGN.md 24): every failed query is recorded before wpdb::query() clears it.
add_filter('query', 'devdadcl_db_guard_record', 1);

// "Report this error" (core 1.7.0): this plugin keeps no log, so a report carries its state (counts and flags,
// never a notice text), and every excerpt-style text passes the plugin's own redactor before it leaves the site.
add_filter('devdcorev1_error_report_log', function ($lines, $plugin) {
    if ($plugin !== 'devdome-admin-cleaner') {
        return $lines;
    }
    $s   = function_exists('devdadcl_get_settings') ? devdadcl_get_settings() : array();
    $sum = function_exists('devdadcl_hub_summary') ? devdadcl_hub_summary() : array();
    $lc  = function_exists('devdadcl_last_change') ? devdadcl_last_change() : null;
    return array(
        'schema=' . (int) get_option('devdadcl_schema', 0),
        'cleanup_applied=' . (int) (isset($s['cleanup_applied']) ? $s['cleanup_applied'] : 0) . ' client_mode_on=' . (int) (isset($s['client_mode_on']) ? $s['client_mode_on'] : 0) . ' mute_promos_on_cleanup=' . (int) (isset($s['mute_promos_on_cleanup']) ? $s['mute_promos_on_cleanup'] : 0),
        'summary=' . wp_json_encode(array_intersect_key(is_array($sum) ? $sum : array(), array_flip(array('score', 'notices_seen', 'promo_count', 'critical_count', 'muted_count', 'updated_at')))),
        'records=' . count(function_exists('devdadcl_option_keys') ? devdadcl_option_keys('devdadcl_n_') : array()) . ' mutes=' . count(function_exists('devdadcl_option_keys') ? devdadcl_option_keys('devdadcl_m_') : array()),
        'storage_warning=' . wp_json_encode(get_option('devdadcl_storage_warning', null)),
        'last_change=' . ($lc ? (string) $lc['label'] . ' @' . (int) $lc['ts'] : 'none'),
    );
}, 10, 2);
add_filter('devdcorev1_error_report_redact', function ($text, $plugin) {
    return ($plugin === 'devdome-admin-cleaner' && function_exists('devdadcl_redact')) ? devdadcl_redact((string) $text) : $text;
}, 10, 2);

// Legacy-identifier migration ships only in fleet/self-hosted builds (.wporg-strip):
// wp.org installs are fresh and have no old-prefix data to move.
if (file_exists(DEVDADCL_DIR . 'includes/migrate.php')) {
    require_once DEVDADCL_DIR . 'includes/migrate.php';
}
require_once DEVDADCL_DIR . 'includes/score.php';
require_once DEVDADCL_DIR . 'includes/notice-policy.php'; // The single decision about what may be hidden automatically.
require_once DEVDADCL_DIR . 'includes/notice-store.php';  // Per-record storage, identity, redaction, scoped mutes, retention.
require_once DEVDADCL_DIR . 'includes/notices.php';
require_once DEVDADCL_DIR . 'includes/cleanup.php';
require_once DEVDADCL_DIR . 'includes/client-mode.php';
require_once DEVDADCL_DIR . 'includes/abilities.php'; // WordPress Abilities API (6.9+): registers nothing on older versions

if (is_admin()) {
    require_once DEVDADCL_DIR . 'includes/admin.php';
}

/**
 * Capability gate. agencies can scope who runs cleanups without editing code.
 * Used by every mutating handler.
 */
function devdadcl_capability()
{
    return apply_filters('devdadcl_capability', 'manage_options');
}

// DevDome Tools hub: register this plugin in the suite dashboard (decoupled. a new plugin is
// ~10 lines, zero hub edits). Tiles + health read ONLY the cached summary option (no query on render).
add_filter('devdcorev1_suite_register', function ($r) {
    $r['devdome-admin-cleaner'] = array(
        'slug'     => 'devdome-admin-cleaner',
        'name'     => __('Admin Cleaner', 'devdome-admin-cleaner'),
        'desc'     => __('Clean admin notices, dashboard clutter &amp; client screens in one click.', 'devdome-admin-cleaner'),
        'icon'     => 'dashicons-hidden',
        'version'  => DEVDADCL_VERSION,
        'page'     => 'devdome-admin-cleaner',
        'position' => 90,
        'schema'   => 1,
        'tiles'    => function () {
            if (!function_exists('devdadcl_hub_summary')) {
                return array();
            }
            $s     = devdadcl_hub_summary();
            $href  = 'admin.php?page=devdome-admin-cleaner';
            $score = (int) $s['score'];
            $muted = (int) $s['muted_count'];
            $nags  = (int) $s['promo_count'];
            // Clutter score: HIGH = cluttered = bad. Invert for state colour.
            return array(
                array('label' => __('Clutter estimate', 'devdome-admin-cleaner'), 'value' => $score, 'fmt' => 'int', 'state' => $score <= 25 ? 'good' : ($score <= 50 ? 'warn' : 'urgent'), 'href' => $href),
                array('label' => __('Muted notices', 'devdome-admin-cleaner'), 'value' => $muted, 'fmt' => 'int', 'state' => $muted ? 'good' : 'idle', 'href' => $href . '&ac_tab=inbox'),
                array('label' => __('Visible promos', 'devdome-admin-cleaner'), 'value' => $nags,  'fmt' => 'int', 'state' => $nags ? 'warn' : 'good', 'href' => $href . '&ac_tab=inbox'),
            );
        },
        'health'   => function () {
            if (!function_exists('devdadcl_hub_summary')) {
                return null;
            }
            $s     = devdadcl_hub_summary();
            $href  = admin_url('admin.php?page=devdome-admin-cleaner');
            $score = (int) $s['score'];
            // Health score is "cleanliness": invert the clutter score so higher = better.
            $clean = max(0, min(100, 100 - $score));
            $issues = array();
            if (!(int) $s['cleanup_applied']) {
                $issues[] = array(
                    'problem'        => __('Your WordPress admin has not been cleaned yet.', 'devdome-admin-cleaner'),
                    'why_it_matters' => __('Notices, nags and dashboard clutter make wp-admin noisy and slow to work in.', 'devdome-admin-cleaner'),
                    'fix'            => __('Apply the Recommended cleanup.', 'devdome-admin-cleaner'),
                    'actions'        => array(array('label' => __('Clean my admin', 'devdome-admin-cleaner'), 'href' => $href)),
                );
            }
            if ((int) $s['promo_count'] > 0) {
                $issues[] = array(
                    'problem'        => __('Promo nags are cluttering your admin.', 'devdome-admin-cleaner'),
                    /* translators: %d is the number of visible promotional notices. */
                    'why_it_matters' => sprintf(_n('%d promotional notice is visible.', '%d promotional notices are visible.', (int) $s['promo_count'], 'devdome-admin-cleaner'), (int) $s['promo_count']),
                    'fix'            => __('Snooze or mute them from the Notice Inbox.', 'devdome-admin-cleaner'),
                    'actions'        => array(array('label' => __('Open Notice Inbox', 'devdome-admin-cleaner'), 'href' => $href . '&ac_tab=inbox')),
                );
            }
            if ((int) $s['client_risk_count'] > 0 && !(int) $s['client_mode_on']) {
                $issues[] = array(
                    'problem'        => __('Client Mode is off while users hold a client role.', 'devdome-admin-cleaner'),
                    /* translators: %d is the number of users holding a configured client role. */
                    'why_it_matters' => sprintf(_n('%d user holds a configured client role and sees the full admin menus. Client Mode is cosmetic simplification only. It does not change what that user is allowed to do.', '%d users hold a configured client role and see the full admin menus. Client Mode is cosmetic simplification only. It does not change what those users are allowed to do.', (int) $s['client_users'], 'devdome-admin-cleaner'), (int) $s['client_users']),
                    'fix'            => __('Turn on Client Mode if you want those users to get a simpler workspace.', 'devdome-admin-cleaner'),
                    'actions'        => array(array('label' => __('Set up Client Mode', 'devdome-admin-cleaner'), 'href' => $href . '&ac_tab=client')),
                );
            }
            // This is a workspace-preference estimate, never an urgent health signal: it must
            // not page anyone or outrank real health issues in the hub (audit batch 4).
            return array(
                'score'       => $clean,
                'status'      => $clean >= 75 ? 'good' : 'warn',
                'scope_label' => __('Admin', 'devdome-admin-cleaner'),
                'summary'     => '',
                'issues'      => $issues,
            );
        },
    );
    return $r;
});

// Recent-activity digest section (read-only, from the cached summary).
add_filter('devdcorev1_suite_report_sections', function ($s) {
    if (!function_exists('devdadcl_hub_summary')) {
        return $s;
    }
    $sum = devdadcl_hub_summary();
    $s[] = array('title' => __('Admin Cleaner', 'devdome-admin-cleaner'), 'lines' => array(
        /* translators: 1: notices seen in the last 30 days, 2: muted notices. */
        sprintf(__('%1$d notices (30 days), %2$d muted', 'devdome-admin-cleaner'), (int) $sum['notices_seen'], (int) $sum['muted_count']),
        /* translators: %d is the number of visible promotional notices. */
        sprintf(_n('%d visible promo', '%d visible promos', (int) $sum['promo_count'], 'devdome-admin-cleaner'), (int) $sum['promo_count']),
        /* translators: %d is the 0-100 clutter estimate. */
        sprintf(__('Clutter estimate %d/100', 'devdome-admin-cleaner'), (int) $sum['score']),
    ));
    return $s;
});

register_activation_hook(__FILE__, 'devdadcl_activate');
register_deactivation_hook(__FILE__, 'devdadcl_deactivate');

// Settings shortcut on the Plugins screen (audit batch 8).
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . DEVDADCL_PAGE)) . '">' . esc_html__('Settings', 'devdome-admin-cleaner') . '</a>');
    return $links;
});

/* DevDome suite self-hosted updates (admin/cron only - never on the front end). The wp.org build
   defines DEVDCOREV1_WPORG_BUILD and the packager strips the updater class, so the whole block is
   skipped there (wp.org must be the only update source - Guideline 8). */
if ( ! defined( 'DEVDCOREV1_WPORG_BUILD' ) ) {
	require_once __DIR__ . '/includes/class-devdome-suite-updater.php';
	if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		new DevDome_Suite_Updater( __FILE__, 'devdome-admin-cleaner', 'https://api.devdome.com/plugin-updates/devdome-admin-cleaner.json' );
	}
}
