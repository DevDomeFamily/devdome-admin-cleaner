<?php
/**
 * Admin: menu, scoped enqueue, settings save handlers (PRG, nonce + cap), and the tabbed admin page
 * rendered on the shared `.dd-app` design system. Tabs: Overview (admin status verdict + detected counts +
 * one-click clean / restore), Notice Inbox (keep/snooze/hide/restore), Dashboard Widgets, Admin Bar,
 * Client Mode.
 *
 * The heavy mutating handlers (oneclick / notice) live in the
 * engine (cleanup.php + notices.php). This file only OWNS the self-POST settings saves for the
 * Dashboard Widgets / Admin Bar / Client Mode tabs (the engine intentionally does not register them).
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/devdome-tools-menu.php';

/* ----------------------------- menu + enqueue --------------------------- */

function devdadcl_menu()
{
    add_submenu_page(
        defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdcorev1-tools',
        'DevDome Admin Cleaner',
        'Admin Cleaner',
        devdadcl_capability(),
        DEVDADCL_PAGE,
        'devdadcl_render_page',
        7
    );
}
add_action('admin_menu', 'devdadcl_menu');

function devdadcl_enqueue_assets($hook)
{
    if (strpos($hook, DEVDADCL_PAGE) === false) {
        return;
    }
    $css = DEVDADCL_DIR . 'assets/devdome-tools-tw.css';
    $ver = file_exists($css) ? filemtime($css) : DEVDADCL_VERSION;
    wp_enqueue_style('devdadcl-ui', DEVDADCL_URL . 'assets/devdome-tools-tw.css', array(), $ver);
    wp_enqueue_style('dashicons');

    // Screen behaviour (ARIA tabs + confirmations) ships as a real enqueued file — the
    // page prints no inline <script> and no inline event handlers (audit batch 7).
    $js   = DEVDADCL_DIR . 'assets/ac-admin.js';
    $jver = file_exists($js) ? filemtime($js) : DEVDADCL_VERSION;
    wp_enqueue_script('devdadcl-admin', DEVDADCL_URL . 'assets/ac-admin.js', array(), $jver, true);
    if (function_exists('devdcorev1_error_report_assets')) {
        devdcorev1_error_report_assets(); // "Report this error" on the database banners (core 1.7.0)
    }

    // Page-scoped additions on top of the (dd-app-scoped) bundle. Attached to the
    // stylesheet instead of printed as a <style> block in the markup.
    wp_add_inline_style('devdadcl-ui', devdadcl_page_css());
}
add_action('admin_enqueue_scripts', 'devdadcl_enqueue_assets');

/** The page-scoped CSS (tabs, switches, badges, responsive + a11y behaviours). */
function devdadcl_page_css()
{
    return '
        /* Panel spacing: Overview = Safe Media Cleaner (py-6 straight under the tab row); other tabs = Malware Scanner (12px to the first section head, 24px under the last card). The scoped bundle ships none of these utilities. */
        .dd-app .pt-3 { padding-top:.75rem; }
        .dd-app .pb-6 { padding-bottom:1.5rem; }
        .dd-app .py-6 { padding-top:1.5rem; padding-bottom:1.5rem; }
        /* Header pill, warn state (section 18 amber); the bundle ships only the ok pill. */
        .dd-app .dd-pill-warn { border-color:#fde68a; background:#fffbeb; color:#b45309; }
        /* Overview hero = Safe Media Cleaner "Media Library Cleaner" card, 1:1. Button row rules copied from SMC .mc-toolbar/.mc-actions; our POST forms stand where SMC has its .mc-bk-split wrapper (equal-width buttons filling the row). */
        .dd-app .ac-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:16px; }
        .dd-app .ac-actions { flex-wrap:nowrap; width:100%; }
        .dd-app .ac-actions .dd-btn { white-space:nowrap; }
        .dd-app .ac-actions > form { flex:1 1 0; min-width:0; margin:0; }
        .dd-app .ac-actions > form > .dd-btn { width:100%; min-width:0; justify-content:center; font-size:12.5px; padding-left:6px; padding-right:6px; overflow:hidden; }
        .dd-app .dd-btn:disabled { opacity:.45; cursor:not-allowed; }
        /* Stat tile sub value (SMC inline rule; the bundle ships only num + lbl). */
        .dd-app .dd-stat-sub { font-size:11px; color:#9ca3af; margin-top:2px; }
        .dd-app .ac-chip { display:inline-block; font-size:11px; padding:2px 8px; border-radius:999px; background:#f3f4f6; color:#4b5563; margin:0 4px 4px 0; }
        .dd-app .ac-badge-crit { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 7px; border-radius:999px; background:#fef2f2; color:#b91c1c; border:1px solid #ef4444; }
        .dd-app .ac-badge-promo { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 7px; border-radius:999px; background:#fffbeb; color:#b45309; border:1px solid #f59e0b; }
        .dd-app .ac-badge-warn { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 7px; border-radius:999px; background:#fffbeb; color:#b45309; border:1px solid #f59e0b; white-space:nowrap; }
        .dd-app .ac-badge-normal { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 7px; border-radius:999px; background:#f3f4f6; color:#4b5563; border:1px solid #6b7280; }
        /* On/off setting = a plain checkbox on the LEFT of its label, saved by the Save button
           (DESIGN.md: settings are checkboxes + Save; a toggle switch is only for instant-apply). */
        /* Settings rows = the Analytics form-table, 1:1 (owner 2026-09-14, DESIGN.md 25). */
        .dd-app .form-table { margin:0; width:100%; border-collapse:collapse; }
        .dd-app .form-table th { width:210px; padding-right:1.5rem; text-align:left; font-weight:700; color:#4b5563; }
        .dd-app .form-table td, .dd-app .form-table th { border-bottom:1px solid #f9fafb; padding-top:.75rem; padding-bottom:1.25rem; vertical-align:top; font-size:.875rem; line-height:1.25rem; }
        .dd-app .form-table td { color:#374151; }
        .dd-app .form-table tr:last-child > td, .dd-app .form-table tr:last-child > th { border-bottom:0; }
        .dd-app .form-table input[type=checkbox] { margin:0; height:1rem; width:1rem; cursor:pointer; border-radius:.25rem; border-color:#d1d5db; color:#4f46e5; }
        .dd-app .form-table .dd-hint { margin:6px 0 0; }
        /* Tabs: active = underline only, never a focus square (suite rule, owner 2026-08-21). */
        .dd-app .dd-tab:focus, .dd-app .dd-tab:focus-visible { outline:none; box-shadow:none; }
        /* The scoped preflight resets h1 to inherit; match the suite title (20px/700) like Link Monitor. */
        .dd-app h1.text-xl { font-size:1.25rem; line-height:1.75rem; font-weight:700; }
        .dd-app .dd-btn .dashicons { margin-right:5px; }
        /* Header bug-report button: exact twin of the DevDome dashboard button. */
        .dd-app .ac-bug-btn { width:36px; height:36px; border-radius:50px; border:1px solid #dadce0; background:#fff; display:grid; place-items:center; cursor:pointer; color:#5f6368; transition:all .2s; text-decoration:none; }
        .dd-app .ac-bug-btn:hover { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
        .dd-app .ac-bug-btn svg { width:16px; height:16px; }
        .dd-app .ac-bug-btn:focus { outline:none; box-shadow:none; }
        .dd-app .ac-stats-7 { grid-template-columns:repeat(4,minmax(0,1fr)); }
        .dd-app .ac-next { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px; }
        .dd-app .ac-next-title { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:700; color:#1f2937; margin:0 0 8px; }
        .dd-app .ac-next-title .dashicons { color:#4f46e5; font-size:18px; width:18px; height:18px; }
        /* Data lists = DESIGN.md section 19 (.dd-list): the Analytics dashboard table, 1:1. */
        .dd-app .dd-list-wrap { overflow-x:auto; border-top:1px solid #e5e7eb; }
        .dd-app .dd-list { width:100%; border-collapse:separate; border-spacing:0; }
        .dd-app .dd-list thead th, .dd-app .dd-list thead .dd-th { width:auto; background:#f9fafb; padding:8px 12px; text-align:left; font-size:12px; line-height:16px; font-weight:500; color:#374151; letter-spacing:.05em; white-space:nowrap; border-bottom:1px solid #e5e7eb; border-right:1px solid #e5e7eb; }
        .dd-app .dd-list tbody td, .dd-app .dd-list tbody .dd-td { padding:10px 12px; font-size:13px; line-height:18px; color:#374151; vertical-align:middle; border-bottom:1px solid #e5e7eb; border-right:1px solid #e5e7eb; }
        .dd-app .dd-list th:last-child, .dd-app .dd-list td:last-child { border-right:0; }
        .dd-app .dd-list tbody tr:last-child td { border-bottom:0; }
        .dd-app .dd-list tbody tr:hover td { background:#f8fbff; }
        .dd-app .ac-notice-text { white-space:normal; word-break:break-word; }
        .dd-app .ac-badge-ok { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 7px; border-radius:999px; background:#ecfdf5; color:#059669; border:1px solid #10b981; white-space:nowrap; }
        .dd-app .ac-intro { font-size:13px; color:#6b7280; margin:0 0 14px; max-width:66%; } /* DESIGN.md 22.2: intro texts two thirds wide at most */
        .dd-app .ac-notice-src { display:block; font-size:12px; color:#6b7280; margin-top:2px; }
        /* Sortable list headers (Type / Status / First seen): the label is a button, the caret shows the current order. */
        .dd-app .ac-sort-btn { background:none; border:0; padding:0; margin:0; font:inherit; font-weight:700; color:inherit; cursor:pointer; white-space:nowrap; text-decoration:underline dotted #9ca3af; text-underline-offset:3px; }
        .dd-app .ac-sort-btn:hover, .dd-app .ac-sort-btn:focus-visible { color:#111827; }
        .dd-app .ac-sort-ind { display:inline-block; width:12px; margin-left:4px; font-size:12px; color:#9ca3af; }
        .dd-app th[aria-sort="ascending"] .ac-sort-ind, .dd-app th[aria-sort="descending"] .ac-sort-ind { color:#2563eb; }
        /* Typed-word confirmation (DESIGN.md 23, Analytics Disconnect/Reset 1:1): the button hides, the panel asks for the word. */
        .dd-app .ac-typed-confirm { margin-top:8px; border-top:1px solid #f3f4f6; padding-top:14px; }
        .dd-app .ac-typed-warn { margin:0 0 8px; font-weight:600; color:#374151; }
        .dd-app .ac-typed-ask { margin:0 0 8px; color:#374151; }
        .dd-app .ac-typed-input { max-width:240px; margin-bottom:10px; }
        .dd-app .ac-typed-btns { display:flex; gap:10px; }
        .dd-app .ac-actions.ac-typed-open { flex-wrap:wrap; }
        .dd-app .ac-actions form.ac-typed-open { flex:0 0 100%; order:9; }
        /* Lists (DESIGN.md 21.2, Malware Scanner 1:1): bulk actions row above the card, the card holds the table + pager only. */
        .dd-app .ac-bulk { display:flex; flex-wrap:nowrap; align-items:center; gap:10px; margin:0 0 6px; padding:8px 0; position:sticky; top:32px; z-index:30; background:#fff; }
        @media (max-width:782px) { .dd-app .ac-bulk { top:46px; } }
        .dd-app .ac-search { width:240px; height:34px; font-size:13px; }
        .dd-app .ac-bulk .dd-btn, .dd-app .ac-bulk .dd-btn-primary { white-space:nowrap; flex-shrink:0; }
        .dd-app .ac-selcount { font-size:13px; font-weight:600; color:#374151; white-space:nowrap; }
        .dd-app .ac-col-check { width:36px; }
        .dd-app .dd-list .dd-check, .dd-app .ac-col-check .dd-check { width:16px; height:16px; border:1px solid #6b7280; border-radius:4px; margin:0; vertical-align:middle; }
        .dd-app .ac-col-pill { width:1%; white-space:nowrap; padding-right:10px; vertical-align:top; }
        .dd-app .ac-col-pill code { white-space:nowrap; word-break:normal; }
        .dd-app .ac-col-pill .ac-status-hint { display:block; white-space:nowrap; }
        .dd-app .dd-tip { margin-left:4px; }
        .dd-app .ac-bulk .dd-dd { width:240px; }
        .dd-app .ac-bulk .dd-btn-primary:disabled { opacity:.5; cursor:default; }
        .dd-app .dd-dd{position:relative;display:inline-block}
        .dd-app .dd-dd-trigger{display:flex;height:34px;width:100%;cursor:pointer;-webkit-user-select:none;-moz-user-select:none;user-select:none;align-items:center;justify-content:space-between;gap:.5rem;border-radius:.5rem;border:1px solid #9ca3af;background-color:#fff;padding:.375rem .75rem;font-size:13px;font-weight:600;color:#374151;box-shadow:0 1px 3px 0 rgba(0,0,0,.1),0 1px 2px -1px rgba(0,0,0,.1);transition:all .15s cubic-bezier(.4,0,.2,1)}
        .dd-app .dd-dd.is-open .dd-dd-trigger{border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.2),0 1px 3px 0 rgba(0,0,0,.1)}
        .dd-app .dd-dd-chev{height:1rem;width:1rem;flex-shrink:0;color:#6b7280;transition:transform .2s cubic-bezier(.4,0,.2,1)}
        .dd-app .dd-dd.is-open .dd-dd-chev{transform:rotate(180deg)}
        .dd-app .dd-dd-panel{position:absolute;top:100%;left:0;z-index:200;margin-top:.25rem;display:none;max-height:420px;width:max-content;min-width:100%;overflow-y:auto;border-radius:.5rem;border:1px solid #d1d5db;background-color:#fff;padding:.25rem 0;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1)}
        .dd-app .dd-dd.is-open .dd-dd-panel{display:block}
        .dd-app .dd-dd-opt{cursor:pointer;white-space:nowrap;padding:.5rem 1rem;font-size:13px;color:#374151;transition:color .15s,background-color .15s}
        .dd-app .dd-dd-opt.is-selected,.dd-app .dd-dd-opt:hover{background-color:#eef2ff}
        .dd-app .dd-dd-opt.is-selected{font-weight:500;color:#4338ca}
        .dd-app .dd-dd-opt.is-disabled{opacity:.45;cursor:not-allowed;background:none}
        .dd-app .ac-list-card { padding:0; overflow:hidden; }
        .dd-app .ac-list-card .dd-list-wrap { border-top:0; }
        .dd-app .ac-pagination { display:flex; flex-wrap:nowrap; align-items:center; justify-content:space-between; gap:8px; padding:10px 12px; border-top:1px solid #e5e7eb; }
        .dd-app .ac-pagination:empty { display:none; }
        .dd-app .ac-pg-count { white-space:nowrap; font-size:12.5px; color:#3c4043; }
        .dd-app .ac-pg-right { display:flex; flex-wrap:nowrap; align-items:center; gap:12px; flex:none; }
        .dd-app .ac-pg-per { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:500; color:#3c4043; }
        .dd-app .ac-pg-btns { display:flex; align-items:center; gap:4px; }
        .dd-app .page-btn { min-width:30px; height:30px; padding:0 8px; border-radius:6px; border:1px solid #dadce0; background:#fff; color:#3c4043; font-family:inherit; font-size:13px; font-weight:500; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background .12s, border-color .12s, color .12s; }
        .dd-app .page-btn:hover:not(:disabled) { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
        .dd-app .page-btn.active { background:#1967d2; border-color:#1967d2; color:#fff; }
        .dd-app .page-btn:disabled { color:#bdc1c6; cursor:not-allowed; }
        .dd-app .page-btn:focus { outline:none; box-shadow:none; }
        .dd-app .page-gap { color:#94a3b8; padding:0 4px; font-size:13px; user-select:none; }
        .dd-app .page-jump-wrap { position:relative; display:block; }
        .dd-app .page-jump-menu { position:absolute; bottom:calc(100% + 6px); right:0; width:78px; max-height:336px; overflow-y:auto; overscroll-behavior:contain; background:#fff; border:1px solid #dadce0; border-radius:10px; box-shadow:0 12px 32px rgba(15,23,42,.14), 0 2px 8px rgba(15,23,42,.06); padding:4px; z-index:40; display:flex; flex-direction:column; }
        .dd-app .page-jump-menu.hidden { display:none; }
        .dd-app .pjm-item { border:0; background:none; font-family:inherit; font-size:13px; font-weight:500; padding:8px 10px; border-radius:6px; cursor:pointer; color:#3c4043; text-align:center; flex:none; }
        .dd-app .pjm-item:hover { background:#f8fafc; }
        .dd-app .pjm-item.active { background:#e8f0fe; color:#1967d2; font-weight:700; }
        .dd-app .ac-status-hint { display:block; margin-top:4px; font-size:12px; color:#374151; white-space:normal; }
        /* Dialog = DESIGN.md section 20 (.dd-modal). Above the admin bar. */
        .dd-app .dd-modal { position:fixed; inset:0; z-index:100000; display:flex; align-items:center; justify-content:center; }
        .dd-app .dd-modal[hidden] { display:none; }
        .dd-app .dd-modal-backdrop { position:absolute; inset:0; background:rgba(15,23,42,.45); }
        .dd-app .dd-modal-box { position:relative; width:min(560px, calc(100vw - 32px)); background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,.2), 0 8px 10px -6px rgba(0,0,0,.1); }
        .dd-app .dd-modal-head { display:flex; align-items:center; gap:10px; padding:16px 20px; border-bottom:1px solid #f3f4f6; }
        .dd-app .dd-modal-head h3 { margin:0; font-size:16px; font-weight:700; color:#1f2937; }
        .dd-app .dd-modal-x { margin-left:auto; border:0; background:transparent; font-size:22px; line-height:1; color:#4b5563; cursor:pointer; padding:0 2px; }
        .dd-app .dd-modal-x:hover { color:#374151; }
        .dd-app .dd-modal-x:focus { outline:none; box-shadow:none; }
        .dd-app .dd-modal-body { padding:20px; font-size:13px; line-height:1.6; color:#374151; }
        .dd-app .dd-modal-foot { display:flex; justify-content:flex-end; gap:8px; padding:14px 20px; border-top:1px solid #f3f4f6; }
        /* Setting row: checkbox cell on the left, label + hint to its right. */
        /* Readability (Malware Scanner pass, owner 2026-08-29): same sizes, darker text. Body copy and hints #374151, labels #1f2937. */
        .dd-app .dd-hint, .dd-app .description, .dd-app .dd-stat-lbl, .dd-app .dd-empty, .dd-app .dd-list thead th { color:#374151; }
        .dd-app .dd-th { color:#1f2937; }
        .dd-app .dd-tab { color:#374151; }
        .dd-app .dd-tab.is-active { color:#4338ca; }
        .dd-app .dd-tabpanel { display:none; }
        .dd-app .dd-tabpanel.is-active { display:block; }
        /* Responsive: tabs scroll instead of clipping; wide tables scroll inside their card. */
        .dd-app .dd-tabs { overflow-x:auto; flex-wrap:nowrap; scrollbar-width:thin; }
        .dd-app .ac-tablewrap { overflow-x:auto; }
        .dd-app .ac-tablewrap table { min-width:560px; }
        .dd-app .screen-reader-text { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(1px,1px,1px,1px); word-wrap:normal; }
    ';
}

/* ----------------------------- save handlers ---------------------------- */

/**
 * The roles offered in the Client Mode multi-select — discovered DYNAMICALLY from the
 * site's registered roles (audit batch 5). Custom roles appear; Shop Manager appears only
 * when WooCommerce actually registers it; roles holding manage_options are excluded
 * because the capability exemption makes targeting them a no-op the UI must not promise.
 *
 * @return array<string, array{label:string, caps:string[]}> slug => label + the relevant
 *         capabilities the role holds (shown in the preview so "what can this role do
 *         anyway" is visible before targeting it).
 */
function devdadcl_client_role_choices()
{
    if (!function_exists('wp_roles')) {
        return array();
    }
    $relevant = array('activate_plugins', 'edit_theme_options', 'update_core', 'import', 'edit_others_posts', 'manage_woocommerce');
    $out = array();
    foreach (wp_roles()->roles as $slug => $def) {
        $caps = isset($def['capabilities']) && is_array($def['capabilities']) ? $def['capabilities'] : array();
        if (!empty($caps['manage_options'])) {
            continue; // exempt by capability — offering it would promise something Client Mode cannot do
        }
        $has = array();
        foreach ($relevant as $cap) {
            if (!empty($caps[$cap])) {
                $has[] = $cap;
            }
        }
        $out[$slug] = array(
            'label' => function_exists('translate_user_role') ? translate_user_role($def['name']) : $def['name'],
            'caps'  => $has,
        );
    }
    return $out;
}

/**
 * One on/off setting row, 1:1 the Analytics Settings layout (owner 2026-09-14, DESIGN.md 25): label cell on the
 * left, then the checkbox with its one word ("Hide" / "Enable"), the one-line hint with the info icon under it.
 * Saved by the Save Settings footer; the self-POST handlers read $_POST[$name] as before (absent = off).
 */
function devdadcl_setting_row($name, $label, $hint = '', $tip = '', $checked = 0, $word = null)
{
    if ($word === null) {
        $word = __('Hide', 'devdome-admin-cleaner');
    }
    ?>
    <tr>
        <th scope="row"><?php echo esc_html($label); ?></th>
        <td>
            <label class="dd-opt"><input type="checkbox" class="dd-check" form="ac-settings-form" id="ac-sw-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" value="1" <?php checked((int) $checked, 1); ?>> <?php echo esc_html($word); ?></label>
            <?php if ('' !== $hint) : ?>
                <p class="dd-hint"><?php echo esc_html($hint); ?><?php if ('' !== $tip) { echo ' '; devdadcl_tip($tip); } ?></p>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

/**
 * The ONE settings save for every tab (self-POST, nonce + cap + guard window, PRG). Every checkbox on the page belongs
 * to #ac-settings-form, so an unticked box is simply absent from the POST: absent = off, for every key at once.
 */
function devdadcl_handle_settings_save()
{
    if (empty($_POST['devdadcl_settings_save'])) {
        return;
    }
    if (!current_user_can(devdadcl_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-admin-cleaner'));
    }
    check_admin_referer('devdadcl_settings', '_acsn');
    devdadcl_db_guard_begin(); // guard window (DESIGN.md 24) // capability + nonce + guard window (DESIGN.md 24)

    $s = devdadcl_get_settings();
    $flags = array(
        'hide_welcome_panel', 'hide_at_a_glance', 'hide_activity', 'hide_quick_draft', 'hide_primary_news', 'hide_site_health',
        'hide_plugin_widgets', 'hide_ab_wp_logo', 'hide_ab_comments', 'hide_ab_new_content', 'hide_ab_updates', 'hide_ab_customize', 'hide_plugin_bar_items', 'hide_footer_text', 'hide_footer_version', 'hide_plugin_row_notices',
        'show_bell', 'auto_hide_new', 'client_mode_on',
        'client_hide_plugins', 'client_hide_tools', 'client_hide_settings', 'client_hide_theme_editor', 'client_hide_plugin_editor', 'client_hide_updates', 'client_hide_version',
    );
    foreach ($flags as $f) {
        $s[$f] = empty($_POST[$f]) ? 0 : 1;
    }
    // Roles: allowlist against the dynamically discovered choices.
    $allowed = array_keys(devdadcl_client_role_choices());
    $roles = array();
    if (!empty($_POST['client_roles']) && is_array($_POST['client_roles'])) {
        foreach (array_map('sanitize_text_field', (array) wp_unslash($_POST['client_roles'])) as $role) { // a nested entry sanitizes to '' (sanitize_key would fatal on it, DeepSeek round 7)
            $role = sanitize_key($role);
            if (in_array($role, $allowed, true)) {
                $roles[] = $role;
            }
        }
    }
    $s['client_roles'] = array_values(array_unique($roles));
    // The old UI offered "Appearance editor" and "Theme editor" as two switches that both target theme-editor.php.
    // One switch remains; the legacy flag mirrors it so the engine (which ORs the two) and old stored settings stay consistent.
    $s['client_hide_appearance_editor'] = $s['client_hide_theme_editor'];

    $w     = devdadcl_save_settings_with_undo($s, __('Settings save', 'devdome-admin-cleaner')); // proved + undoable (DESIGN.md 24.5; DeepSeek round 6)
    $saved = !empty($w['ok']);
    if (function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    $tab = isset($_POST['ac_tab']) && is_string($_POST['ac_tab']) ? sanitize_key(wp_unslash($_POST['ac_tab'])) : 'overview';
    if (!in_array($tab, array('overview', 'inbox', 'dashboard-widgets', 'admin-bar', 'client'), true)) {
        $tab = 'overview';
    }
    devdadcl_finish($saved
        ? array('page' => DEVDADCL_PAGE, 'ac_tab' => $tab, 'ac_saved' => '1')
        : array('page' => DEVDADCL_PAGE, 'ac_tab' => $tab, 'ac_error' => (isset($w['snapshot']) && false === $w['snapshot'] && !empty($w['settings_saved'])) ? 'snapshot' : 'save'));
}
add_action('admin_init', 'devdadcl_handle_settings_save');

/* ----------------------------- page shell ------------------------------- */

/** Allowlist of valid tabs (slug => [label, dashicon]). The first entry is the default. */
function devdadcl_tabs()
{
    return array(
        'overview'          => array(__('Overview', 'devdome-admin-cleaner'), 'dashicons-dashboard'),
        'inbox'             => array(__('Notice Inbox', 'devdome-admin-cleaner'), 'dashicons-email-alt'),
        'dashboard-widgets' => array(__('Dashboard Widgets', 'devdome-admin-cleaner'), 'dashicons-screenoptions'),
        'admin-bar'         => array(__('Admin Bar', 'devdome-admin-cleaner'), 'dashicons-admin-generic'),
        'client'            => array(__('Client Mode', 'devdome-admin-cleaner'), 'dashicons-groups'),
    );
}

function devdadcl_render_page()
{
    $tabs = devdadcl_tabs();
    $tab  = isset($_GET['ac_tab']) && is_string($_GET['ac_tab']) ? sanitize_key(wp_unslash($_GET['ac_tab'])) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector; ?ac_tab[]= must not fatal (DeepSeek round 7)
    if ('dashboard' === $tab) {
        $tab = 'overview'; // the first tab used to be called Dashboard; old ?ac_tab= links still land here
    }
    if (!isset($tabs[$tab])) {
        $tab = 'overview';
    }
    // The page is built inside a guard window and buffered (DESIGN.md 24): a query that failed while it was
    // built gets a red banner on top instead of a screen that quietly shows defaults.
    devdadcl_db_guard_begin();
    ob_start();
    $s = devdadcl_hub_summary();
    // A handler that hit a database error (ac_error=db) or a save that did not land (ac_error=save): one red
    // banner over every tab, with "Report this error" (DESIGN.md 26). Flags are display-only (set by our own PRG).
    $err = isset($_GET['ac_error']) && is_string($_GET['ac_error']) ? sanitize_key(wp_unslash($_GET['ac_error'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $why = isset($_GET['ac_why']) && is_string($_GET['ac_why']) ? sanitize_text_field(wp_unslash($_GET['ac_why'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $err_text = '';
    if ('db' === $err) {
        $err_text = '' !== $why ? $why : __('A database query failed during this action. The result is not trusted and nothing more was changed: reload the page and check the current state before trying again.', 'devdome-admin-cleaner');
    } elseif ('save' === $err) {
        $err_text = __('The change could not be saved: the database did not keep it. Reload the page and check the current state before trying again; if it keeps happening, check the database with your host.', 'devdome-admin-cleaner');
    } elseif ('confirm' === $err) {
        $err_text = __('That action needs its confirmation: use the button on this screen and confirm the dialog (or type the word) so the request carries it.', 'devdome-admin-cleaner');
    } elseif ('snapshot' === $err) {
        $err_text = __('The cleanup settings were applied, but the undo snapshot could not be stored and the settings could not be rolled back: Undo will not offer this change. Check the database with your host.', 'devdome-admin-cleaner');
    } elseif ('mute' === $err) {
        $err_text = __('The cleanup settings were applied, but some notices could not be hidden: the database did not keep their mutes. Press Clean My Admin again; if it keeps happening, check the database with your host.', 'devdome-admin-cleaner');
    }
    ?>
    <div class="dd-app min-h-screen bg-gray-50 text-[#3c434a] font-sans text-[13px]">
        <?php if ('' !== $err_text) : ?>
            <div class="dd-banner" data-ac-flash="1" style="margin:16px 24px 0;border-color:#fecaca;background:#fef2f2;color:#991b1b;"><?php echo esc_html($err_text); ?><?php if (function_exists('devdcorev1_error_report_button')) { echo devdcorev1_error_report_button('devdome-admin-cleaner', DEVDADCL_VERSION, ('db' === $err ? 'Database error during an Admin Cleaner action: ' : 'Settings save did not land: ') . $err_text, 'Admin Cleaner ' . $tab); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the core helper ?></div>
        <?php endif; ?>
        <div class="bg-white border-b border-gray-200 shadow-sm">
            <div class="max-w-5xl px-6 py-4 flex items-center gap-3">
                <div class="p-1.5 rounded text-white inline-flex" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);"><span class="dashicons dashicons-hidden"></span></div>
                <h1 class="text-xl font-bold text-gray-800 m-0"><?php esc_html_e('Admin Cleaner', 'devdome-admin-cleaner'); ?></h1>
                <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
                    <?php if ((int) $s['promo_count'] > 0) : ?>
                        <span class="dd-pill dd-pill-warn"><span class="dashicons dashicons-flag" style="font-size:14px;width:14px;height:14px;"></span> <?php
                            /* translators: %d is the number of visible promotional notices. */
                            printf(esc_html(_n('%d promo visible', '%d promos visible', (int) $s['promo_count'], 'devdome-admin-cleaner')), (int) $s['promo_count']);
                        ?></span>
                    <?php elseif ((int) $s['cleanup_applied']) : ?>
                        <span class="dd-pill dd-pill-ok"><span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Cleanup applied', 'devdome-admin-cleaner'); ?></span>
                    <?php else : ?>
                        <span class="dd-pill"><?php esc_html_e('Not cleaned yet', 'devdome-admin-cleaner'); ?></span>
                    <?php endif; ?>
                    <a class="ac-bug-btn" href="<?php echo esc_url('https://devdome.com/report-bug?plugin=devdome-admin-cleaner&v=' . DEVDADCL_VERSION); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('Report a bug', 'devdome-admin-cleaner'); ?>" aria-label="<?php esc_attr_e('Report a bug', 'devdome-admin-cleaner'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>
                    </a>
                </div>
            </div>
            <div class="dd-tabs max-w-5xl" style="margin:0;border-bottom:0;" role="tablist" aria-label="<?php esc_attr_e('Admin Cleaner sections', 'devdome-admin-cleaner'); ?>">
                <?php foreach ($tabs as $slug => $meta) : $active = ($tab === $slug); ?>
                    <a class="dd-tab <?php echo esc_attr($active ? 'is-active' : ''); ?>"
                       href="#<?php echo esc_attr($slug); ?>"
                       role="tab"
                       id="ac-tab-<?php echo esc_attr($slug); ?>"
                       aria-controls="ac-panel-<?php echo esc_attr($slug); ?>"
                       aria-selected="<?php echo esc_attr($active ? 'true' : 'false'); ?>"
                       tabindex="<?php echo esc_attr($active ? '0' : '-1'); ?>"
                       data-dd-tab="<?php echo esc_attr($slug); ?>"><span class="dashicons <?php echo esc_attr($meta[1]); ?>" aria-hidden="true"></span> <?php echo esc_html($meta[0]); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <main>
            <?php
            // All panels render once; switching is instant client-side (no page reload). The
            // $tab from ?ac_tab= still picks the initial active panel so form-save PRG
            // redirects land right. Inactive panels carry hidden as well as the CSS class,
            // so assistive tech agrees with the visual state.
            $panels = array(
                'overview'          => 'devdadcl_render_overview_tab',
                'inbox'             => 'devdadcl_render_inbox_tab',
                'dashboard-widgets' => 'devdadcl_render_widgets_tab',
                'admin-bar'         => 'devdadcl_render_adminbar_tab',
                'client'            => 'devdadcl_render_client_tab',
            );
            foreach ($panels as $slug => $renderer) :
                $active = ($tab === $slug);
                ?>
                <div class="dd-tabpanel<?php echo esc_attr($active ? ' is-active' : ''); ?>"
                     id="ac-panel-<?php echo esc_attr($slug); ?>"
                     role="tabpanel"
                     aria-labelledby="ac-tab-<?php echo esc_attr($slug); ?>"
                     tabindex="0"
                     data-dd-panel="<?php echo esc_attr($slug); ?>"
                     <?php echo esc_attr($active ? '' : 'hidden'); ?>>
                    <?php in_array($slug, array('overview', 'inbox'), true) ? call_user_func($renderer, $s) : call_user_func($renderer); ?>
                </div>
            <?php endforeach; ?>
        </main>
        <?php // ONE settings form for every tab (owner 2026-09-14): the checkboxes carry form="ac-settings-form", so a change on
              // any tab is counted and saved together; the sticky footer shows only while something differs, or right after a save. ?>
        <form method="post" id="ac-settings-form" style="display:contents;"><?php // display:contents: the sticky footer's containing block is the whole page, so it stays in view while a long tab scrolls (owner 2026-09-15) ?>
            <input type="hidden" name="ac_tab" value="<?php echo esc_attr($tab); ?>">
            <?php wp_nonce_field('devdadcl_settings', '_acsn'); ?>
            <?php devdadcl_save_footer('devdadcl_settings_save', isset($_GET['ac_saved']), true); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag from our own PRG ?>
        </form>
        <div class="dd-modal" id="ac-confirm" hidden role="dialog" aria-modal="true" aria-labelledby="ac-confirm-title">
            <div class="dd-modal-backdrop" data-ac-modal-close></div>
            <div class="dd-modal-box">
                <div class="dd-modal-head"><h3 id="ac-confirm-title"><?php esc_html_e('Please confirm', 'devdome-admin-cleaner'); ?></h3><button type="button" class="dd-modal-x" data-ac-modal-close aria-label="<?php esc_attr_e('Close', 'devdome-admin-cleaner'); ?>">&times;</button></div>
                <div class="dd-modal-body" id="ac-confirm-text"></div>
                <div class="dd-modal-foot"><button type="button" class="dd-btn" data-ac-modal-close><?php esc_html_e('Cancel', 'devdome-admin-cleaner'); ?></button><button type="button" class="dd-btn dd-btn-primary" id="ac-confirm-ok"><?php esc_html_e('Confirm', 'devdome-admin-cleaner'); ?></button></div>
            </div>
        </div>
    </div>
    <?php
    $html = (string) ob_get_clean();
    if (devdadcl_db_guard_failed()) {
        $banner_text = __('A database query failed while this page was built, so what it shows may be incomplete or stale. Reload the page; if it keeps happening, check the database with your host.', 'devdome-admin-cleaner');
        $report_btn  = function_exists('devdcorev1_error_report_button') ? devdcorev1_error_report_button('devdome-admin-cleaner', DEVDADCL_VERSION, 'Admin Cleaner page built over a failed database query: ' . (function_exists('devdadcl_redact') ? devdadcl_redact(devdadcl_db_guard_error()) : devdadcl_db_guard_error()), 'Admin Cleaner page') : '';
        $banner      = '<div class="dd-banner" style="margin:16px 24px 0;border-color:#fecaca;background:#fef2f2;color:#991b1b;">' . esc_html($banner_text) . $report_btn . '</div>';
        $html        = preg_replace_callback('/(<div class="dd-app[^>]*>)/', function ($m) use ($banner) { return $m[1] . $banner; }, $html, 1); // no $1 in the banner text can act as a backreference (DeepSeek round 6)
    }
    devdadcl_db_guard_end();
    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the buffered page, escaped where built
}

/* ----------------------------- overview tab ----------------------------- */

function devdadcl_render_overview_tab($s)
{
    // Cleanliness ring = the inverse of the 0-100 clutter estimate (higher = cleaner), the same
    // inversion the hub health card uses. Never computed yet => empty ring, not a made-up 100.
    $computed  = (int) $s['updated_at'] > 0;
    $score     = $computed ? max(0, min(100, 100 - (int) $s['score'])) : 0;
    $score_fmt = $computed ? (string) $score : '-';
    // Section 18 colours on the hub thresholds (clutter <= 25 good, <= 50 warn, else urgent).
    $ring_col  = $score >= 75 ? '#10b981' : ($score >= 50 ? '#f59e0b' : '#ef4444');
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own PRG redirects.
    $cleaned   = isset($_GET['ac_cleaned']);
    $muted_n   = isset($_GET['ac_muted']) ? (int) $_GET['ac_muted'] : null;
    $refreshed = isset($_GET['ac_refreshed']);
    $undone    = isset($_GET['ac_undone']) ? (int) $_GET['ac_undone'] : null;
    $unmuted   = isset($_GET['ac_unmuted']) ? (int) $_GET['ac_unmuted'] : null;
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    $snap = devdadcl_last_change();

    // Hidden-by-settings counts for the Hidden items tile (the cached summary tracks what is still SHOWN).
    $hidden_widgets = 0;
    foreach (array('hide_welcome_panel', 'hide_at_a_glance', 'hide_activity', 'hide_quick_draft', 'hide_primary_news', 'hide_site_health', 'hide_plugin_widgets') as $f) {
        $hidden_widgets += devdadcl_get_int($f, 0) ? 1 : 0;
    }
    $hidden_bar = 0;
    foreach (array('hide_ab_wp_logo', 'hide_ab_comments', 'hide_ab_new_content', 'hide_ab_updates', 'hide_ab_customize', 'hide_plugin_bar_items') as $f) {
        $hidden_bar += devdadcl_get_int($f, 0) ? 1 : 0;
    }
    ?>
    <div class="max-w-5xl px-6 py-6">
        <?php if ($cleaned) : ?>
            <div class="dd-banner" data-ac-flash="1" style="border-color:#a7f3d0;background:#ecfdf5;color:#065f46;margin-bottom:16px;">
                <?php
                if ($muted_n !== null) {
                    /* translators: %d is the number of notices newly hidden for all administrators. */
                    printf(esc_html(_n('Cleanup applied: dashboard and toolbar clutter hidden and %d notice newly hidden for all administrators. Undo last change reverses the settings.', 'Cleanup applied: dashboard and toolbar clutter hidden and %d notices newly hidden for all administrators. Undo last change reverses the settings.', $muted_n, 'devdome-admin-cleaner')), (int) $muted_n);
                } else {
                    esc_html_e('Cleanup applied. Undo last change reverses the settings.', 'devdome-admin-cleaner');
                }
                ?>
            </div>
        <?php endif; ?>
        <?php if ($refreshed) : ?>
            <div class="dd-banner" data-ac-flash="1" style="border-color:#bfdbfe;background:#eff6ff;color:#1e40af;margin-bottom:16px;"><?php esc_html_e('Summary refreshed from current data.', 'devdome-admin-cleaner'); ?></div>
        <?php endif; ?>
        <?php if ($undone === 1) : ?>
            <div class="dd-banner" data-ac-flash="1" style="border-color:#a7f3d0;background:#ecfdf5;color:#065f46;margin-bottom:16px;"><?php esc_html_e('The last settings change was undone. Your previous configuration is back exactly as it was.', 'devdome-admin-cleaner'); ?></div>
        <?php elseif ($undone === 2) : ?>
            <div class="dd-banner" data-ac-flash="1" style="border-color:#fde68a;background:#fffbeb;color:#92400e;margin-bottom:16px;"><?php esc_html_e('Your previous settings were restored, but the undo snapshot could not be cleared, so Undo may offer the same change again.', 'devdome-admin-cleaner'); ?></div>
        <?php elseif ($undone === 0) : ?>
            <div class="dd-banner" data-ac-flash="1" style="border-color:#fde68a;background:#fffbeb;color:#92400e;margin-bottom:16px;"><?php esc_html_e('Nothing to undo.', 'devdome-admin-cleaner'); ?></div>
        <?php endif; ?>
        <?php if ($unmuted !== null) : ?>
            <div class="dd-banner" data-ac-flash="1" style="border-color:#a7f3d0;background:#ecfdf5;color:#065f46;margin-bottom:16px;">
                <?php
                /* translators: %d is the number of cleared mutes. */
                printf(esc_html(_n('%d mute cleared. Every previously hidden notice is visible again.', '%d mutes cleared. Every previously hidden notice is visible again.', $unmuted, 'devdome-admin-cleaner')), (int) $unmuted);
                ?>
            </div>
        <?php endif; ?>

        <!-- ============ Admin Cleaner hero (Safe Media Cleaner "Media Library Cleaner" card, 1:1) ============ -->
        <div class="dd-card" style="margin-bottom:20px;">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:24px;">
                <?php
                // Same SVG ring as Safe Media Cleaner (r=70, 12px stroke), coloured by score.
                $circ = 2 * M_PI * 70;
                $dash = max(0, min(100, $score)) / 100 * $circ;
                ?>
                <div class="dd-donut">
                    <svg width="160" height="160" viewBox="0 0 160 160">
                        <circle cx="80" cy="80" r="70" fill="none" stroke="#e5e7eb" stroke-width="12"></circle>
                        <circle cx="80" cy="80" r="70" fill="none" stroke="<?php echo esc_attr($ring_col); ?>" stroke-width="12" stroke-linecap="round"
                            stroke-dasharray="<?php echo esc_attr(round($dash, 1) . ' ' . round($circ, 1)); ?>"
                            transform="rotate(-90 80 80)"></circle>
                    </svg>
                    <span class="dd-donut-num"><?php echo esc_html($score_fmt); ?></span>
                    <span class="dd-donut-lbl"><?php esc_html_e('Cleanliness', 'devdome-admin-cleaner'); ?></span>
                </div>
                <div style="flex:1;min-width:260px;">
                    <h2 class="dd-h2" style="margin:0 0 4px;"><?php esc_html_e('Admin Cleaner', 'devdome-admin-cleaner'); ?></h2>
                    <p style="color:#6b7280;margin:0 0 14px;max-width:520px;"><?php esc_html_e('Clean My Admin hides every dashboard widget, every extra admin-bar item, the footer text and every admin notice showing right now, for all administrators. Everything is reversible: switch a widget or bar item back on in its tab, or restore a notice from the Notice Inbox.', 'devdome-admin-cleaner'); ?></p>
                    <div class="ac-toolbar ac-actions" style="margin:0;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="devdadcl_oneclick">
                            <?php wp_nonce_field('devdadcl_oneclick', '_acnonce'); ?>
                            <button type="submit" class="dd-btn dd-btn-primary"><span class="dashicons dashicons-hidden"></span> <?php esc_html_e('Clean My Admin', 'devdome-admin-cleaner'); ?></button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="devdadcl_undo">
                            <?php wp_nonce_field('devdadcl_undo', '_acnonce'); ?>
                            <button type="submit" class="dd-btn" <?php disabled($snap === null); ?> title="<?php echo esc_attr($snap !== null ? $snap['label'] : __('Nothing to undo', 'devdome-admin-cleaner')); ?>"><span class="dashicons dashicons-undo"></span> <?php esc_html_e('Undo last change', 'devdome-admin-cleaner'); ?></button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ac-confirm="<?php echo esc_attr(__('Clear every mute (all scopes, all users) so every hidden notice is visible again?', 'devdome-admin-cleaner')); ?>">
                            <input type="hidden" name="action" value="devdadcl_clear_mutes">
                            <?php wp_nonce_field('devdadcl_clear_mutes', '_acnonce'); ?>
                            <button type="submit" class="dd-btn"><span class="dashicons dashicons-visibility"></span> <?php esc_html_e('Clear all mutes', 'devdome-admin-cleaner'); ?></button>
                        </form>
                    </div>
                    <?php // Auto-hide new notices (owner 2026-09-14): the checkbox lives here, the sticky Save Settings footer of this tab saves it. ?>
                    <div class="ac-autohide" style="margin:14px 0 0;">
                        <label class="dd-opt" style="margin:0;"><input type="checkbox" class="dd-check" name="auto_hide_new" value="1" form="ac-settings-form" <?php checked(devdadcl_get_int('auto_hide_new', 0), 1); ?>> <?php esc_html_e('Auto-hide new notices', 'devdome-admin-cleaner'); ?></label>
                        <p class="dd-hint" style="margin:6px 0 0;"><?php esc_html_e('Every notice that appears for the first time is hidden for all administrators at once and lands in the Notice Inbox.', 'devdome-admin-cleaner'); ?> <?php devdadcl_tip(__('Switched on by Clean My Admin. Widgets and admin-bar items are settings and stay hidden anyway; this covers the notices that plugins add later. Restore any notice from the inbox.', 'devdome-admin-cleaner')); ?></p>
                    </div>
                </div>
            </div>

            <div class="dd-stats ac-stats-5" style="margin-top:18px;grid-template-columns:repeat(5,1fr);">
                <?php
                // DESIGN.md 18: indigo = neutral count, red = bad, amber = needs attention, green = handled/clean, gray = muted.
                $crit_vis   = (int) $s['critical_count'];
                $crit_hid   = (int) (isset($s['critical_hidden']) ? $s['critical_hidden'] : 0);
                $promo_vis  = (int) $s['promo_count'];
                $muted      = (int) $s['muted_count'];
                $hidden_ui  = (int) ($hidden_widgets + $hidden_bar);
                ?>
                <div class="dd-stat"><div class="dd-stat-num" style="color:#4f46e5;"><?php echo (int) $s['notices_seen']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Notices (30 days)', 'devdome-admin-cleaner'); ?></div><div class="dd-stat-sub">
                    <?php
                    if ($computed) {
                        /* translators: %s is a human-readable time difference, e.g. "5 mins". */
                        printf(esc_html__('Updated %s ago', 'devdome-admin-cleaner'), esc_html(human_time_diff((int) $s['updated_at'])));
                    } else {
                        esc_html_e('Not computed yet', 'devdome-admin-cleaner');
                    }
                    ?>
                </div></div>
                <div class="dd-stat"><div class="dd-stat-num" style="color:<?php echo esc_attr($crit_vis > 0 ? '#ef4444' : '#10b981'); ?>;"><?php echo (int) $crit_vis; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Critical visible', 'devdome-admin-cleaner'); ?></div><div class="dd-stat-sub">
                    <?php
                    /* translators: %d is the number of critical notices hidden for all admins. */
                    printf(esc_html(_n('%d critical hidden', '%d critical hidden', $crit_hid, 'devdome-admin-cleaner')), (int) $crit_hid); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer
                    ?>
                </div></div>
                <div class="dd-stat"><div class="dd-stat-num" style="color:<?php echo esc_attr($promo_vis > 0 ? '#f59e0b' : '#10b981'); ?>;"><?php echo (int) $promo_vis; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Promos visible', 'devdome-admin-cleaner'); ?></div><div class="dd-stat-sub"><?php echo (int) $s['cleanup_applied'] ? esc_html__('Cleanup applied', 'devdome-admin-cleaner') : esc_html__('Not cleaned yet', 'devdome-admin-cleaner'); ?></div></div>
                <div class="dd-stat"><div class="dd-stat-num" style="color:#6b7280;"><?php echo (int) $muted; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Notices hidden', 'devdome-admin-cleaner'); ?></div><div class="dd-stat-sub"><?php esc_html_e('Restore any from the inbox', 'devdome-admin-cleaner'); ?></div></div>
                <div class="dd-stat"><div class="dd-stat-num" style="color:<?php echo esc_attr($hidden_ui > 0 ? '#10b981' : '#4f46e5'); ?>;"><?php echo (int) $hidden_ui; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Hidden items', 'devdome-admin-cleaner'); ?></div><div class="dd-stat-sub">
                    <?php
                    /* translators: 1: built-in dashboard widgets still shown, 2: built-in admin-bar items still shown. */
                    printf(esc_html__('%1$d widgets and %2$d bar items shown', 'devdome-admin-cleaner'), (int) $s['active_widgets'], (int) $s['admin_bar_items']);
                    ?>
                </div></div>
            </div>
        </div>

        <!-- Quick links to the tabs (the numbers live in the hero tiles above, SMC layout) -->
        <div class="dd-sec-head"><span class="dashicons dashicons-admin-tools dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Fine-tune', 'devdome-admin-cleaner'); ?></h2></div>
        <div class="ac-next">
            <div class="dd-card">
                <p class="ac-next-title"><span class="dashicons dashicons-email-alt"></span><?php esc_html_e('Notice Inbox', 'devdome-admin-cleaner'); ?></p>
                <p style="color:#374151;margin:0 0 12px;"><?php esc_html_e('Snooze, hide or restore every admin notice. Critical ones carry a badge so you can bring them back when you need them.', 'devdome-admin-cleaner'); ?></p>
                <a class="dd-btn dd-btn-sm" href="#inbox"><?php esc_html_e('Open inbox', 'devdome-admin-cleaner'); ?></a>
            </div>
            <div class="dd-card">
                <p class="ac-next-title"><span class="dashicons dashicons-screenoptions"></span><?php esc_html_e('Dashboard & admin bar', 'devdome-admin-cleaner'); ?></p>
                <p style="color:#374151;margin:0 0 12px;"><?php esc_html_e('Pick exactly which dashboard widgets and admin-bar items to hide.', 'devdome-admin-cleaner'); ?></p>
                <a class="dd-btn dd-btn-sm" href="#dashboard-widgets"><?php esc_html_e('Choose widgets', 'devdome-admin-cleaner'); ?></a>
            </div>
            <div class="dd-card">
                <p class="ac-next-title"><span class="dashicons dashicons-groups"></span><?php esc_html_e('Client Mode', 'devdome-admin-cleaner'); ?></p>
                <p style="color:#374151;margin:0 0 12px;"><?php esc_html_e('Hide menu shortcuts so client roles get a simpler workspace (cosmetic only, capabilities unchanged).', 'devdome-admin-cleaner'); ?></p>
                <a class="dd-btn dd-btn-sm" href="#client"><?php esc_html_e('Set up Client Mode', 'devdome-admin-cleaner'); ?></a>
            </div>
        </div>
    </div>
    <?php
}

/* ----------------------------- notice inbox tab ------------------------- */

/**
 * Info icon with the long explanation in a hover box (DESIGN.md 21.6, one helper per plugin): the visible
 * hint stays one line, the icon carries the why and the consequence.
 */
function devdadcl_tip($text)
{
    echo '<span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">' . esc_html($text) . '</span></span>';
}

/**
 * The custom dropdown every DevDome plugin uses (Malware Scanner devdmalw_render_dropdown, 1:1): a hidden
 * input carries the value, the panel lists the options, ac-admin.js drives it. $options = value => label,
 * the '' entry is the placeholder shown on the closed trigger and never offered in the list.
 */
function devdadcl_render_dropdown($name, $options, $label = '')
{
    $id          = 'devdadcl-select-' . sanitize_key($name);
    $placeholder = isset($options['']) ? (string) $options[''] : (string) reset($options);
    ?>
    <div class="dd-dd" data-name="<?php echo esc_attr($name); ?>" data-placeholder="<?php echo esc_attr($placeholder); ?>" id="<?php echo esc_attr($id); ?>">
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="">
        <div class="dd-dd-trigger" tabindex="0" role="button" aria-haspopup="listbox" aria-expanded="false"<?php echo $label !== '' ? ' aria-label="' . esc_attr($label) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute fragment, the value is esc_attr()'d above. ?>>
            <span class="dd-dd-label"><?php echo esc_html($placeholder); ?></span>
            <svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
        </div>
        <div class="dd-dd-panel" role="listbox"<?php echo $label !== '' ? ' aria-label="' . esc_attr($label) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute fragment, the value is esc_attr()'d above. ?>>
            <?php foreach ($options as $val => $option_label) : ?>
                <?php if ((string) $val === '') { continue; } ?>
                <div class="dd-dd-opt" role="option" tabindex="-1" aria-selected="false" data-value="<?php echo esc_attr($val); ?>"><?php echo esc_html($option_label); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/**
 * Bulk actions row above a list (DESIGN.md 21.2, Malware Scanner twin): selection count, the Actions
 * dropdown, Apply, and a search box on the right. Sticky under the admin bar while the list scrolls.
 */
function devdadcl_render_bulk_bar($count_id, $apply_id, $action_name, $options, $search_id = '')
{
    ?>
    <div class="ac-bulk">
        <span class="ac-selcount" id="<?php echo esc_attr($count_id); ?>" aria-live="polite"><?php echo esc_html(sprintf(/* translators: %d is the number of selected rows. */ __('Selected (%d)', 'devdome-admin-cleaner'), 0)); ?></span>
        <?php devdadcl_render_dropdown($action_name, $options, __('Actions', 'devdome-admin-cleaner')); ?>
        <button type="button" class="dd-btn-primary" id="<?php echo esc_attr($apply_id); ?>" disabled><?php esc_html_e('Apply', 'devdome-admin-cleaner'); ?></button>
        <?php if ($search_id !== '') : ?>
            <span style="flex:1;"></span>
            <label class="screen-reader-text" for="<?php echo esc_attr($search_id); ?>"><?php esc_html_e('Search', 'devdome-admin-cleaner'); ?></label>
            <input type="search" id="<?php echo esc_attr($search_id); ?>" class="dd-input ac-search" placeholder="<?php esc_attr_e('Search notice or source...', 'devdome-admin-cleaner'); ?>">
        <?php endif; ?>
    </div>
    <?php
}

function devdadcl_render_inbox_tab($s)
{
    $inbox = devdadcl_get_inbox();
    $now   = time();
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own PRG redirects.
    $done   = isset($_GET['ac_done']) && is_string($_GET['ac_done']) ? sanitize_key(wp_unslash($_GET['ac_done'])) : null;
    $bulk   = isset($_GET['ac_bulk']) && is_string($_GET['ac_bulk']) ? sanitize_text_field(wp_unslash($_GET['ac_bulk'])) : null;
    $purged = isset($_GET['ac_purged']) ? (int) $_GET['ac_purged'] : null;
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    $warn   = (int) get_option('devdadcl_storage_warning', 0);

    // Newest-seen first.
    uasort($inbox, function ($a, $b) {
        return ((int) $b['last_seen']) <=> ((int) $a['last_seen']);
    });
    $date_fmt = (string) get_option('date_format');
    if ('' === $date_fmt) {
        $date_fmt = 'F j, Y';
    }
    $uid      = get_current_user_id();
    // The actions menu: every per-row action of the old button grid, applied to the selection. Entries that
    // do not fit EVERY selected row are greyed by ac-admin.js (restore needs a hidden row, the rest a visible one).
    $actions = array(
        ''            => __('Actions', 'devdome-admin-cleaner'),
        'snooze1'     => __('Snooze 1 day (you)', 'devdome-admin-cleaner'),
        'snooze7'     => __('Snooze 7 days (you)', 'devdome-admin-cleaner'),
        'hide'        => __('Hide (you)', 'devdome-admin-cleaner'),
        'hide_admins' => __('Hide for all admins', 'devdome-admin-cleaner'),
        'restore'     => __('Restore', 'devdome-admin-cleaner'),
        'delete'      => __('Remove from inbox', 'devdome-admin-cleaner'),
    );
    ?>
    <div class="max-w-5xl px-6 pt-3 pb-6">
        <?php if ('1' === $done) : ?>
            <div class="dd-banner" data-ac-flash="1" style="border-color:#a7f3d0;background:#ecfdf5;color:#065f46;margin-bottom:16px;"><?php esc_html_e('Notice updated.', 'devdome-admin-cleaner'); ?></div>
        <?php elseif ('0' === $done) : ?>
            <div class="dd-banner" data-ac-flash="1" style="border-color:#fde68a;background:#fffbeb;color:#92400e;margin-bottom:16px;"><?php esc_html_e('Nothing changed. The notice was already in that state.', 'devdome-admin-cleaner'); ?></div>
        <?php endif; ?>
        <?php if ($bulk !== null && preg_match('/^(\d+)\/(\d+)$/', $bulk, $bm)) : ?>
            <?php if ((int) $bm[2] === 0) : ?>
                <div class="dd-banner" data-ac-flash="1" style="border-color:#fde68a;background:#fffbeb;color:#92400e;margin-bottom:16px;"><?php esc_html_e('Nothing was selected.', 'devdome-admin-cleaner'); ?></div>
            <?php elseif ((int) $bm[1] === (int) $bm[2]) : ?>
                <div class="dd-banner" data-ac-flash="1" style="border-color:#a7f3d0;background:#ecfdf5;color:#065f46;margin-bottom:16px;">
                    <?php
                    /* translators: %d is the number of notices the action was applied to. */
                    printf(esc_html(_n('%d notice updated.', '%d notices updated.', (int) $bm[2], 'devdome-admin-cleaner')), (int) $bm[2]);
                    ?>
                </div>
            <?php else : ?>
                <div class="dd-banner" data-ac-flash="1" style="border-color:#fde68a;background:#fffbeb;color:#92400e;margin-bottom:16px;">
                    <?php
                    /* translators: 1: notices changed, 2: notices selected. */
                    printf(esc_html__('%1$d of %2$d notices updated. The others were already in that state.', 'devdome-admin-cleaner'), (int) $bm[1], (int) $bm[2]);
                    ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($purged !== null) : ?>
            <div class="dd-banner" data-ac-flash="1" style="border-color:#a7f3d0;background:#ecfdf5;color:#065f46;margin-bottom:16px;">
                <?php
                /* translators: %d is the number of deleted notice records. */
                printf(esc_html(_n('Deleted %d stored notice record and every mute.', 'Deleted %d stored notice records and every mute.', $purged, 'devdome-admin-cleaner')), (int) $purged);
                ?>
            </div>
        <?php endif; ?>
        <?php if ($warn) : ?>
            <div class="dd-banner" data-ac-flash="1" style="border-color:#fecaca;background:#fef2f2;color:#b91c1c;margin-bottom:16px;"><?php esc_html_e('Storage is over its cap, but the extra records are unresolved protected notices, so nothing was discarded. Resolve or remove some to get back under the cap.', 'devdome-admin-cleaner'); ?></div>
        <?php endif; ?>

        <p class="ac-intro"><?php esc_html_e('Every admin notice the plugin has seen. Select rows, pick an action and press Apply: snooze the noisy ones, hide for good, or restore anything hidden. Critical notices carry a badge so they are easy to find.', 'devdome-admin-cleaner'); ?></p>

        <?php if (!$inbox && !empty($GLOBALS['devdadcl_option_keys_failed'])) : ?>
            <div class="dd-card" style="margin-bottom:32px;"><div class="dd-empty"><?php esc_html_e('The notice list could not be read from the database. Reload the page; if it keeps happening, check the database with your host.', 'devdome-admin-cleaner'); ?></div></div>
        <?php elseif (!$inbox) : ?>
            <div class="dd-card" style="margin-bottom:32px;"><div class="dd-empty"><?php esc_html_e('No admin notices captured yet. As notices appear in wp-admin they will be collected here.', 'devdome-admin-cleaner'); ?></div></div>
        <?php else : ?>
            <?php devdadcl_render_bulk_bar('ac-inbox-count', 'ac-inbox-apply', 'ac_inbox_action', $actions, 'ac-inbox-search'); ?>
            <div class="dd-card ac-list-card" style="margin-bottom:32px;">
                <div class="dd-list-wrap"><table class="dd-table dd-list" id="ac-inbox-table" data-ac-paged="20">
                    <caption class="screen-reader-text"><?php esc_html_e('Captured admin notices with their type, status, first seen date, text and source', 'devdome-admin-cleaner'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col" class="ac-col-check"><input type="checkbox" class="dd-check" id="ac-inbox-checkall" aria-label="<?php esc_attr_e('Select all notices shown', 'devdome-admin-cleaner'); ?>"></th>
                            <th scope="col" class="dd-th ac-col-pill" data-ac-sort="text"><button type="button" class="ac-sort-btn" aria-label="<?php esc_attr_e('Sort by type', 'devdome-admin-cleaner'); ?>"><?php esc_html_e('Type', 'devdome-admin-cleaner'); ?><span class="ac-sort-ind" aria-hidden="true">&#8645;</span></button></th>
                            <th scope="col" class="dd-th ac-col-pill" data-ac-sort="text"><button type="button" class="ac-sort-btn" aria-label="<?php esc_attr_e('Sort by status', 'devdome-admin-cleaner'); ?>"><?php esc_html_e('Status', 'devdome-admin-cleaner'); ?><span class="ac-sort-ind" aria-hidden="true">&#8645;</span></button></th>
                            <th scope="col" class="dd-th ac-col-pill" data-ac-sort="num"><button type="button" class="ac-sort-btn" aria-label="<?php esc_attr_e('Sort by first seen', 'devdome-admin-cleaner'); ?>"><?php esc_html_e('First seen', 'devdome-admin-cleaner'); ?><span class="ac-sort-ind" aria-hidden="true">&#8645;</span></button></th>
                            <th scope="col" class="dd-th" data-ac-sort="text"><button type="button" class="ac-sort-btn" aria-label="<?php esc_attr_e('Sort by notice text', 'devdome-admin-cleaner'); ?>"><?php esc_html_e('Notice', 'devdome-admin-cleaner'); ?><span class="ac-sort-ind" aria-hidden="true">&#8645;</span></button></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($inbox as $hash => $rec) :
                        $is_critical = !empty($rec['is_critical']);
                        $is_promo    = !empty($rec['is_promo']);

                        // Current status from the per-notice mute row, WITH its scope: every status says who it affects.
                        $status_label = __('Visible', 'devdome-admin-cleaner');
                        $status_hint  = '';
                        $status_muted = false;
                        $mrec = devdadcl_mute_get($hash);
                        if ($mrec !== null) {
                            if (devdadcl_until_active($mrec['admins'], $now)) {
                                $status_muted = true;
                                if ((int) $mrec['admins'] === 0) {
                                    $status_label = __('Hidden', 'devdome-admin-cleaner');
                                    $status_hint  = __('For all admins', 'devdome-admin-cleaner');
                                } else {
                                    $status_label = __('Snoozed', 'devdome-admin-cleaner');
                                    /* translators: %s is a date. */
                                    $status_hint  = sprintf(__('All admins, until %s', 'devdome-admin-cleaner'), date_i18n($date_fmt, (int) $mrec['admins']));
                                }
                            } elseif (isset($mrec['users'][$uid]) && devdadcl_until_active($mrec['users'][$uid], $now)) {
                                $status_muted = true;
                                if ((int) $mrec['users'][$uid] === 0) {
                                    $status_label = __('Hidden', 'devdome-admin-cleaner');
                                    $status_hint  = __('For you', 'devdome-admin-cleaner');
                                } else {
                                    $status_label = __('Snoozed', 'devdome-admin-cleaner');
                                    /* translators: %s is a date. */
                                    $status_hint  = sprintf(__('For you, until %s', 'devdome-admin-cleaner'), date_i18n($date_fmt, (int) $mrec['users'][$uid]));
                                }
                            }
                        }
                        if (!$status_muted && $is_critical) {
                            $status_hint = __('Critical: visible', 'devdome-admin-cleaner');
                        }
                    ?>
                        <tr data-hash="<?php echo esc_attr($hash); ?>" data-muted="<?php echo esc_attr($status_muted ? '1' : '0'); ?>" data-muted-admins="<?php echo ($mrec !== null && devdadcl_until_active($mrec['admins'], $now)) ? '1' : '0'; ?>" data-critical="<?php echo esc_attr($is_critical ? '1' : '0'); ?>">
                            <td class="ac-col-check"><input type="checkbox" class="dd-check ac-row-check" value="<?php echo esc_attr($hash); ?>" aria-label="<?php esc_attr_e('Select this notice', 'devdome-admin-cleaner'); ?>"></td>
                            <td class="dd-td ac-col-pill">
                                <?php if ($is_critical) : ?>
                                    <span class="ac-badge-crit"><?php esc_html_e('Critical', 'devdome-admin-cleaner'); ?></span>
                                <?php elseif ($is_promo) : ?>
                                    <span class="ac-badge-promo"><?php esc_html_e('Promo', 'devdome-admin-cleaner'); ?></span>
                                <?php else : ?>
                                    <span class="ac-badge-normal"><?php esc_html_e('Normal', 'devdome-admin-cleaner'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="dd-td ac-col-pill">
                                <span class="<?php echo esc_attr($status_muted ? ('Snoozed' === $status_label || __('Snoozed', 'devdome-admin-cleaner') === $status_label ? 'ac-badge-warn' : 'ac-badge-normal') : 'ac-badge-ok'); ?>"><?php echo esc_html($status_label); ?></span>
                                <?php if ($status_hint !== '') : ?>
                                    <span class="ac-status-hint"><?php echo esc_html($status_hint); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="dd-td ac-col-pill" data-sort="<?php echo (int) $rec['first_seen']; ?>"><code><?php echo esc_html(date_i18n($date_fmt, (int) $rec['first_seen'])); ?></code></td>
                            <td class="dd-td ac-notice-text">
                                <?php echo esc_html($rec['excerpt']); ?>
                                <span class="ac-notice-src"><?php echo esc_html($rec['source']); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div class="ac-pagination"></div>
            </div>
            <?php // The bulk form ac-admin.js fills (hashes[] + do) and submits after the confirmation dialog. ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ac-inbox-bulk-form" hidden>
                <input type="hidden" name="action" value="devdadcl_notice_bulk">
                <input type="hidden" name="do" value="">
                <?php wp_nonce_field('devdadcl_notice_bulk', '_acnonce'); ?>
            </form>
        <?php endif; ?>

        <?php // Privacy: delete every stored notice record + mute. Settings are not touched. ?>
        <div class="dd-sec-head"><span class="dashicons dashicons-trash dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Delete all notice data', 'devdome-admin-cleaner'); ?></h2></div>
        <div class="dd-card">
            <p class="ac-intro"><?php esc_html_e('Removes every stored notice record and every mute from this site. Your cleanup settings are not touched. Notices still being emitted will be captured again as fresh entries: visible, or hidden again at once while Auto-hide new notices is on.', 'devdome-admin-cleaner'); ?></p>
            <?php // DESIGN.md 23: destructive = type the word (same flow as Analytics Disconnect / Reset). ac-admin.js drives form[data-ac-typed]. ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;" data-ac-typed="DELETE">
                <input type="hidden" name="action" value="devdadcl_purge_notices">
                <?php wp_nonce_field('devdadcl_purge_notices', '_acnonce'); ?>
                <button type="button" class="dd-btn dd-btn-danger" data-ac-typed-open><?php esc_html_e('Delete all notice data', 'devdome-admin-cleaner'); ?></button>
                <div class="ac-typed-confirm" hidden>
                    <p class="ac-typed-warn"><?php esc_html_e('This permanently deletes every stored notice record and every mute on this site. It cannot be undone.', 'devdome-admin-cleaner'); ?></p>
                    <p class="ac-typed-ask"><?php echo wp_kses(__('Type <strong>DELETE</strong> to confirm.', 'devdome-admin-cleaner'), array('strong' => array())); ?></p>
                    <input type="text" class="dd-input ac-typed-input" autocomplete="off" placeholder="DELETE" aria-label="<?php esc_attr_e('Type DELETE to confirm', 'devdome-admin-cleaner'); ?>">
                    <div class="ac-typed-btns">
                        <button type="submit" class="dd-btn dd-btn-danger" disabled><?php esc_html_e('Delete all notice data', 'devdome-admin-cleaner'); ?></button>
                        <button type="button" class="dd-btn" data-ac-typed-cancel><?php esc_html_e('Cancel', 'devdome-admin-cleaner'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Sticky save footer = DESIGN.md section 16 (Save button) inside the Safe Media Cleaner / Malware
 * Scanner .dd-footer, 1:1. Rendered INSIDE the tab's <form> so the button submits it; the panel's
 * pb-6 absorbs the footer's negative bottom margin.
 */
function devdadcl_save_footer($name, $saved, $autoshow = false)
{
    // $autoshow: the footer is hidden until a field differs from what is stored (ac-admin.js shows it), or right after a save.
    ?>
    <footer class="dd-footer" style="margin:2rem 1.5rem 1.5rem;max-width:61rem;"<?php if ($autoshow) : ?> data-dd-autoshow="1"<?php if (!$saved) : ?> hidden<?php endif; endif; ?>>
        <div class="dd-footer-inner">
            <div class="dd-footer-actions">
                <button type="submit" name="<?php echo esc_attr($name); ?>" value="1" class="dd-btn-primary" style="gap:8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                    <?php esc_html_e('Save Settings', 'devdome-admin-cleaner'); ?>
                </button>
                <?php if ($saved) : ?><span class="dd-saved" style="color:#059669;font-weight:600;font-size:13px;"><?php esc_html_e('Saved.', 'devdome-admin-cleaner'); ?></span><?php endif; ?>
                <?php // DESIGN.md 16.1: "N changes not saved" while the form differs from what is stored; ac-admin.js fills it. ?>
                <?php /* translators: %d is the number of settings changed but not yet saved. */ ?>
                <span class="dd-pending" hidden style="color:#b91c1c;font-weight:600;font-size:13px;" data-one="<?php esc_attr_e('1 change not saved', 'devdome-admin-cleaner'); ?>" data-many="<?php esc_attr_e('%d changes not saved', 'devdome-admin-cleaner'); ?>"></span>
            </div>
        </div>
    </footer>
    <?php
}

/* ----------------------------- dashboard widgets tab -------------------- */

function devdadcl_render_widgets_tab()
{
    $rows = array(
        'hide_welcome_panel' => array(__('Welcome panel', 'devdome-admin-cleaner'), __('The big “Welcome to WordPress” getting-started panel.', 'devdome-admin-cleaner'), __('Shown to every user until dismissed. Hiding it removes the panel for everyone; the same links live in the admin menu.', 'devdome-admin-cleaner')),
        'hide_at_a_glance'   => array(__('At a Glance', 'devdome-admin-cleaner'), __('Post / page / comment counts and the WordPress version line (and the network version on a multisite).', 'devdome-admin-cleaner'), __('Hides the counts and the WordPress version line for everyone. The numbers stay on the Posts, Pages and Comments screens.', 'devdome-admin-cleaner')),
        'hide_activity'      => array(__('Activity', 'devdome-admin-cleaner'), __('Recently published posts and recent comments.', 'devdome-admin-cleaner'), __('Hides recent posts and comments from the dashboard for everyone. Comments still show in the toolbar bubble and on the Comments screen.', 'devdome-admin-cleaner')),
        'hide_quick_draft'   => array(__('Quick Draft', 'devdome-admin-cleaner'), __('The quick “write a draft” box on the dashboard.', 'devdome-admin-cleaner'), __('Hides the draft box only. Drafts are still created from Posts, Add New.', 'devdome-admin-cleaner')),
        'hide_primary_news'  => array(__('WordPress Events & News', 'devdome-admin-cleaner'), __('The WordPress.org events and news feed widget.', 'devdome-admin-cleaner'), __('Hides the WordPress.org feed and stops the dashboard from fetching it on every load, which also makes the dashboard a little faster.', 'devdome-admin-cleaner')),
        'hide_plugin_widgets' => array(__('Widgets added by plugins', 'devdome-admin-cleaner'), __('Every dashboard widget a plugin or theme registered.', 'devdome-admin-cleaner'), __('Removes every non-core dashboard box (SEO, forms, shop, backup and news widgets). Untick to see them again; the plugins themselves are untouched.', 'devdome-admin-cleaner')),
        'hide_site_health'   => array(__('Site Health Status', 'devdome-admin-cleaner'), __('The Site Health summary widget and the PHP / browser update nags.', 'devdome-admin-cleaner'), __('Hides the summary widget only. Site Health itself stays under Tools and keeps checking; a critical issue is then only visible there.', 'devdome-admin-cleaner')),
    );
    ?>
    <div class="max-w-5xl px-6 pt-3 pb-6">
            <div class="dd-card">
                <div class="ac-tablewrap"><table class="form-table">
                    <?php foreach ($rows as $key => $meta) : ?>
                        <?php devdadcl_setting_row($key, $meta[0], $meta[1], isset($meta[2]) ? $meta[2] : '', devdadcl_get_int($key, 0)); ?>
                    <?php endforeach; ?>
                </table></div>
            </div>
    </div>
    <?php
}

/* ----------------------------- admin bar tab ---------------------------- */

function devdadcl_render_adminbar_tab()
{
    $rows = array(
        'hide_ab_wp_logo'     => array(__('WordPress logo', 'devdome-admin-cleaner'), __('The WordPress “W” logo menu on the far left of the toolbar.', 'devdome-admin-cleaner'), __('Hides the W menu (About WordPress, Documentation, Support) from the toolbar for everyone. All of it stays reachable from the Dashboard.', 'devdome-admin-cleaner')),
        'hide_ab_comments'    => array(__('Comments', 'devdome-admin-cleaner'), __('The comment-bubble shortcut in the toolbar.', 'devdome-admin-cleaner'), __('Hides the pending-comments bubble for everyone. Comments still arrive and are moderated from the Comments screen; nobody is told about new ones in the toolbar.', 'devdome-admin-cleaner')),
        'hide_ab_new_content' => array(__('+ New', 'devdome-admin-cleaner'), __('The “+ New” add-content menu.', 'devdome-admin-cleaner'), __('Hides the add-content menu from the toolbar. Every Add New button on the admin screens stays.', 'devdome-admin-cleaner')),
        'hide_ab_updates'     => array(__('Updates', 'devdome-admin-cleaner'), __('The updates-available indicator.', 'devdome-admin-cleaner'), __('Hides the updates counter from the toolbar for everyone. Updates are still listed under Dashboard, Updates, so check it on a schedule; an unpatched plugin is the usual way in.', 'devdome-admin-cleaner')),
        'hide_plugin_bar_items' => array(__('Items added by plugins', 'devdome-admin-cleaner'), __('Every top-level toolbar item a plugin or theme added.', 'devdome-admin-cleaner'), __('Removes every non-core toolbar entry (SEO, cache, forms, page builders). Core items keep their own switches above.', 'devdome-admin-cleaner')),
        'hide_ab_customize'   => array(__('Customize', 'devdome-admin-cleaner'), __('The “Customize” theme shortcut.', 'devdome-admin-cleaner'), __('Hides the Customize shortcut. The Customizer stays reachable under Appearance.', 'devdome-admin-cleaner')),
    );
    ?>
    <div class="max-w-5xl px-6 pt-3 pb-6">
            <div class="dd-card">
                <div class="ac-tablewrap"><table class="form-table">
                    <?php foreach ($rows as $key => $meta) : ?>
                        <?php devdadcl_setting_row($key, $meta[0], $meta[1], isset($meta[2]) ? $meta[2] : '', devdadcl_get_int($key, 0)); ?>
                    <?php endforeach; ?>
                </table></div>
            </div>

            <div class="dd-sec-head" style="margin-top:32px;"><span class="dashicons dashicons-admin-appearance dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Footer & version', 'devdome-admin-cleaner'); ?></h2></div>
            <div class="dd-card">
                <div class="ac-tablewrap"><table class="form-table">
                    <?php devdadcl_setting_row('hide_footer_text', __('Footer “Thank you” text', 'devdome-admin-cleaner'), __('Hide the admin footer text.', 'devdome-admin-cleaner'), __('Cosmetic only, the line at the bottom of every admin screen. Plugins that write their own footer text are not affected.', 'devdome-admin-cleaner'), devdadcl_get_int('hide_footer_text', 0)); ?>
                    <?php devdadcl_setting_row('hide_plugin_row_notices', __('Plugin list notices', 'devdome-admin-cleaner'), __('The notice rows plugins print under their entry on the Plugins screen.', 'devdome-admin-cleaner'), __('Drops the "Upgrade to Pro", licence and update rows plugins add under their own line on the Plugins list. The list itself and the Updates screen are untouched.', 'devdome-admin-cleaner'), devdadcl_get_int('hide_plugin_row_notices', 0)); ?>
                    <?php devdadcl_setting_row('hide_footer_version', __('WordPress version', 'devdome-admin-cleaner'), __('Hide the version number in the admin footer.', 'devdome-admin-cleaner'), __('Hides the number for everyone. It is not a security measure: the version stays readable from the site HTML and feeds, so keep WordPress updated instead.', 'devdome-admin-cleaner'), devdadcl_get_int('hide_footer_version', 0)); ?>
                </table></div>
            </div>

            <div class="dd-sec-head" style="margin-top:32px;"><span class="dashicons dashicons-hidden dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Notice bell', 'devdome-admin-cleaner'); ?></h2></div>
            <div class="dd-card">
                <div class="ac-tablewrap"><table class="form-table">
                    <?php devdadcl_setting_row('show_bell', __('Admin-bar bell', 'devdome-admin-cleaner'), __('Show a bell in the toolbar with the muted-notice count.', 'devdome-admin-cleaner'), __('The bell counts the notices hidden for the current user and links to the inbox. Turn it off for a completely quiet toolbar; the inbox still keeps everything.', 'devdome-admin-cleaner'), devdadcl_get_int('show_bell', 1), __('Enable', 'devdome-admin-cleaner')); ?>
                </table></div>
            </div>

    </div>
    <?php
}

/* ----------------------------- client mode tab -------------------------- */

function devdadcl_render_client_tab()
{
    $on = devdadcl_get_int('client_mode_on', 0);
    $roles = devdadcl_get_array('client_roles', array('editor', 'author'));
    $choices = devdadcl_client_role_choices();
    $rows = array(
        'client_hide_plugins'       => array(__('Plugins menu', 'devdome-admin-cleaner'), __('Hides the Plugins menu entry.', 'devdome-admin-cleaner'), __('Hides the Plugins entry from the targeted roles. A role that may manage plugins can still open plugins.php by URL; take the capability away for real control.', 'devdome-admin-cleaner')),
        'client_hide_tools'         => array(__('Tools menu', 'devdome-admin-cleaner'), __('Hides the Tools menu entry.', 'devdome-admin-cleaner'), __('Hides the Tools entry only. Tools the role may use (Import, Export, Site Health) stay reachable by URL.', 'devdome-admin-cleaner')),
        'client_hide_settings'      => array(__('Settings menu', 'devdome-admin-cleaner'), __('Hides the core Settings menu entry.', 'devdome-admin-cleaner'), __('Hides the core Settings entry for the targeted roles. Settings screens stay reachable by URL for roles that hold manage_options; administrators are always exempt.', 'devdome-admin-cleaner')),
        'client_hide_theme_editor'  => array(__('Theme file editor', 'devdome-admin-cleaner'), __('Hides the menu entry and redirects theme-editor.php to the dashboard.', 'devdome-admin-cleaner'), __('The file editor is the classic way to plant code. Besides hiding the entry, theme-editor.php redirects to the dashboard for the targeted roles.', 'devdome-admin-cleaner')),
        'client_hide_plugin_editor' => array(__('Plugin file editor', 'devdome-admin-cleaner'), __('Hides the menu entry and redirects plugin-editor.php to the dashboard.', 'devdome-admin-cleaner'), __('The file editor is the classic way to plant code. Besides hiding the entry, plugin-editor.php redirects to the dashboard for the targeted roles.', 'devdome-admin-cleaner')),
        'client_hide_updates'       => array(__('Updates screen', 'devdome-admin-cleaner'), __('Hides the menu entry and redirects update-core.php to the dashboard.', 'devdome-admin-cleaner'), __('Hides the Updates entry and redirects update-core.php to the dashboard for the targeted roles. Somebody must still run updates: make sure an exempt administrator does.', 'devdome-admin-cleaner')),
        'client_hide_version'       => array(__('WordPress version', 'devdome-admin-cleaner'), __('Hides the version number in the footer for targeted users.', 'devdome-admin-cleaner'), __('Hides the version number in the footer for the targeted users only. Cosmetic: the version stays readable from the site HTML.', 'devdome-admin-cleaner')),
    );
    ?>
    <div class="max-w-5xl px-6 pt-3 pb-6">
            <?php // Same layout as Dashboard Widgets / Admin Bar (owner 2026-09-14): one card, rows one under another, no intro text. ?>
            <div class="dd-card">
                <div class="ac-tablewrap"><table class="form-table">
                    <?php devdadcl_setting_row('client_mode_on', __('Client Mode', 'devdome-admin-cleaner'), __('Apply the list below to the roles you tick.', 'devdome-admin-cleaner'), __('Cosmetic only: menus are hidden, permissions are not changed. Administrators are never affected, whatever roles they also hold.', 'devdome-admin-cleaner'), $on, __('Enable', 'devdome-admin-cleaner')); ?>
                    <?php foreach ($choices as $role => $meta) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($meta['label']); ?></th>
                            <td><label class="dd-opt"><input type="checkbox" class="dd-check" id="ac-role-<?php echo esc_attr($role); ?>" form="ac-settings-form" name="client_roles[]" value="<?php echo esc_attr($role); ?>" <?php checked(in_array($role, $roles, true)); ?>> <?php esc_html_e('Apply', 'devdome-admin-cleaner'); ?></label></td>
                        </tr>
                    <?php endforeach; ?>
                </table></div>
            </div>

            <div class="dd-sec-head" style="margin-top:32px;"><span class="dashicons dashicons-hidden dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('What they do not see', 'devdome-admin-cleaner'); ?></h2></div>
            <div class="dd-card">
                <div class="ac-tablewrap"><table class="form-table">
                    <?php foreach ($rows as $key => $meta) : ?>
                        <?php devdadcl_setting_row($key, $meta[0], $meta[1], isset($meta[2]) ? $meta[2] : '', devdadcl_get_int($key, 1)); ?>
                    <?php endforeach; ?>
                </table></div>
            </div>
    </div>
    <?php
}

