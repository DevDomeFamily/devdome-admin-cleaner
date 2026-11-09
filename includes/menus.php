<?php
/**
 * Hidden menu items per role (1.0.11): any wp-admin menu or submenu, including the ones other plugins and the theme
 * add, hidden for the roles an administrator picks. Part of Client Mode (the master switch on the Client tab).
 *
 * How it works: on every admin page an administrator opens, the menu WordPress built is snapshotted into one option
 * (the catalog), so the Client tab can list every item, plugin menus included. On the page load of a targeted user the
 * picked items are removed late on admin_menu. Hiding removes the link only; the page behind it stays reachable by
 * its address, which is said on the screen. Users who may manage options are never affected.
 *
 * The three helpers that decide things are pure (no WordPress calls), so the CLI suite proves them.
 */

defined('ABSPATH') || exit;

/** Menu titles as WordPress holds them carry markup (update bubbles, screen-reader spans): the plain words only. */
function devdadcl_menus_clean_title($title)
{
    $t = (string) $title;
    $t = preg_replace('~<span[^>]*class="[^"]*(screen-reader-text|update-plugins|awaiting-mod|count-|badge|bubble)[^"]*"[^>]*>.*?</span>~is', '', $t);
    $t = preg_replace('~<span[^>]*>\s*\d+\s*</span>~is', '', $t); // a bare number in a span is a count badge whatever its class (Burst "Statistics 1")
    $t = wp_strip_all_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $t));
}

/**
 * The catalog: array of array(slug, title, items => array(array(slug, title))) from the two globals WordPress builds.
 * Separators and nameless entries are skipped. Pure.
 */
function devdadcl_menus_catalog_from($menu, $submenu)
{
    $out = array();
    if (!is_array($menu)) {
        return $out;
    }
    foreach ($menu as $m) {
        if (!is_array($m) || !isset($m[2]) || (string) $m[2] === '' || strpos((string) $m[2], 'separator') === 0) {
            continue;
        }
        $slug  = (string) $m[2];
        $title = devdadcl_menus_clean_title(isset($m[0]) ? $m[0] : '');
        if ($title === '') {
            continue;
        }
        $items = array();
        if (is_array($submenu) && isset($submenu[$slug]) && is_array($submenu[$slug])) {
            foreach ($submenu[$slug] as $s) {
                if (!is_array($s) || !isset($s[2]) || (string) $s[2] === '') {
                    continue;
                }
                $st = devdadcl_menus_clean_title(isset($s[0]) ? $s[0] : '');
                if ($st === '' || (string) $s[2] === $slug) {
                    continue; // the first submenu entry repeats the parent
                }
                $items[] = array('slug' => (string) $s[2], 'title' => $st);
            }
        }
        $out[] = array('slug' => $slug, 'title' => $title, 'items' => $items);
    }
    return $out;
}

/** Every id the catalog can express: "slug" for a top item, "parent|slug" for a submenu item. Pure. */
function devdadcl_menus_catalog_ids(array $catalog)
{
    $ids = array();
    foreach ($catalog as $top) {
        if (!is_array($top) || empty($top['slug'])) {
            continue;
        }
        $ids[] = (string) $top['slug'];
        foreach ((isset($top['items']) && is_array($top['items'])) ? $top['items'] : array() as $it) {
            if (is_array($it) && !empty($it['slug'])) {
                $ids[] = $top['slug'] . '|' . $it['slug'];
            }
        }
    }
    return $ids;
}

/**
 * The posted picks, allowlisted: only known roles (the Client Mode choices, never a role that may manage options)
 * and only ids the catalog holds. Returns role => list of ids. Pure.
 */
function devdadcl_menus_sanitize($posted, array $catalog, array $allowed_roles)
{
    $ids = array_flip(devdadcl_menus_catalog_ids($catalog));
    $out = array();
    if (!is_array($posted)) {
        return $out;
    }
    foreach ($posted as $role => $list) {
        $role = sanitize_key((string) $role);
        if ($role === '' || !in_array($role, $allowed_roles, true) || !is_array($list)) {
            continue;
        }
        $keep = array();
        foreach ($list as $id) {
            $id = is_string($id) ? trim(wp_unslash($id)) : '';
            if ($id !== '' && isset($ids[$id])) {
                $keep[$id] = true;
            }
        }
        if ($keep) {
            $out[$role] = array_keys($keep);
        }
    }
    return $out;
}

/** The union of hidden ids for the user's roles (any role with a list counts). Pure. */
function devdadcl_menus_hidden_for_roles($map, $user_roles)
{
    $hidden = array();
    if (!is_array($map)) {
        return $hidden;
    }
    foreach ((array) $user_roles as $role) {
        if (isset($map[$role]) && is_array($map[$role])) {
            foreach ($map[$role] as $id) {
                $hidden[(string) $id] = true;
            }
        }
    }
    return array_keys($hidden);
}

/* ------------------------------ WordPress hooks ------------------------------ */

/** Snapshot the menu an administrator sees (plugin menus included) so the Client tab can list every item. */
function devdadcl_menus_capture()
{
    if (!current_user_can('manage_options') || empty($GLOBALS['menu'])) {
        return;
    }
    $catalog = devdadcl_menus_catalog_from($GLOBALS['menu'], isset($GLOBALS['submenu']) ? $GLOBALS['submenu'] : array());
    if (!$catalog) {
        return;
    }
    $stored = get_option('devdadcl_menu_catalog', array());
    if ($stored !== $catalog) {
        update_option('devdadcl_menu_catalog', $catalog, false);
    }
}
add_action('admin_menu', 'devdadcl_menus_capture', 9998);

/** The stored catalog (array), for the Client tab and the save allowlist. */
function devdadcl_menus_catalog()
{
    $c = get_option('devdadcl_menu_catalog', array());
    return is_array($c) ? $c : array();
}

/** Remove the picked items for the current user's roles. Late, so plugin menus exist; never for option managers. */
function devdadcl_menus_apply()
{
    if (!devdadcl_get_int('client_mode_on', 0) || current_user_can('manage_options')) {
        return;
    }
    $user = wp_get_current_user();
    if (!$user || empty($user->roles)) {
        return;
    }
    $hidden = devdadcl_menus_hidden_for_roles(devdadcl_get_array('client_hidden_menus', array()), $user->roles);
    foreach ($hidden as $id) {
        $parts = explode('|', (string) $id, 2);
        if (count($parts) === 2) {
            remove_submenu_page($parts[0], $parts[1]);
        } else {
            remove_menu_page($parts[0]);
        }
    }
}
add_action('admin_menu', 'devdadcl_menus_apply', 9999);

/* ============================ menu order and names (1.0.12) ============================ */
/*
 * Reorder and rename top-level admin menu items, for everyone who sees them (administrators included): the order is
 * a list of slugs, items not in the list keep their place after the listed ones; a rename replaces the words of the
 * title and keeps the update bubble WordPress hangs on it. Both read the same catalog as Hidden Menu Items. Pure
 * helpers first, hooks below.
 */

/** Top-level slugs the catalog knows, in catalog order. Pure. */
function devdadcl_menus_top_slugs(array $catalog)
{
    $out = array();
    foreach ($catalog as $top) {
        if (is_array($top) && !empty($top['slug'])) {
            $out[] = (string) $top['slug'];
        }
    }
    return $out;
}

/** The posted order, allowlisted against the catalog's top-level slugs, duplicates dropped. Pure. */
function devdadcl_menu_order_sanitize($posted, array $catalog)
{
    $known = array_flip(devdadcl_menus_top_slugs($catalog));
    $out   = array();
    foreach (is_array($posted) ? $posted : array() as $slug) {
        $slug = is_string($slug) ? trim(wp_unslash($slug)) : '';
        if ($slug !== '' && isset($known[$slug]) && !in_array($slug, $out, true)) {
            $out[] = $slug;
        }
    }
    return $out;
}

/**
 * Apply a saved order to the slugs WordPress is about to render: listed slugs first, in the saved order, then every
 * other slug in its original order. A saved slug that no longer exists is skipped. Pure.
 */
function devdadcl_menu_order_sorted(array $saved, array $current)
{
    $out = array();
    foreach ($saved as $slug) {
        if (in_array($slug, $current, true) && !in_array($slug, $out, true)) {
            $out[] = $slug;
        }
    }
    foreach ($current as $slug) {
        if (!in_array($slug, $out, true)) {
            $out[] = $slug;
        }
    }
    return $out;
}

/**
 * The posted renames (slug => new title) as a list of [slug, title] pairs: known slugs only, plain text, at most 60
 * characters; an empty title or one equal to the original means "no rename" and is dropped. Pure.
 */
function devdadcl_menu_names_sanitize($posted, array $catalog)
{
    $titles = array();
    foreach ($catalog as $top) {
        if (is_array($top) && !empty($top['slug'])) {
            $titles[(string) $top['slug']] = isset($top['title']) ? (string) $top['title'] : '';
        }
    }
    $out = array();
    foreach (is_array($posted) ? $posted : array() as $slug => $title) {
        $slug = is_string($slug) ? trim(wp_unslash($slug)) : '';
        if ($slug === '' || !isset($titles[$slug]) || !is_string($title)) {
            continue;
        }
        $title = trim(sanitize_text_field(wp_unslash($title)));
        if ($title === '' || $title === $titles[$slug] || strlen($title) > 60) {
            continue;
        }
        $out[] = array($slug, $title);
    }
    return $out;
}

/** The stored pairs as slug => title. Pure. */
function devdadcl_menu_names_map($pairs)
{
    $map = array();
    foreach (is_array($pairs) ? $pairs : array() as $pair) {
        if (is_array($pair) && isset($pair[0], $pair[1]) && is_string($pair[0]) && is_string($pair[1]) && $pair[0] !== '' && $pair[1] !== '') {
            $map[$pair[0]] = $pair[1];
        }
    }
    return $map;
}

/** A menu title with new words and the old markup (update bubble, screen-reader span) kept. Pure. */
function devdadcl_menu_title_renamed($markup, $new_title)
{
    $markup = (string) $markup;
    $pos    = strpos($markup, '<');
    $tail   = ($pos === false) ? '' : substr($markup, $pos);
    return esc_html($new_title) . ($tail !== '' ? ' ' . $tail : '');
}

/* ------------------------------ meta boxes (1.0.12) ------------------------------ */
/*
 * Hidden meta boxes per role, part of Client Mode like Hidden Menu Items: the boxes of an editor screen are
 * snapshotted when an administrator opens it (plugin boxes included), the Client tab lists them per post type, and
 * on a targeted user's editor the picked boxes are removed. Ids are "post_type|box_id".
 */

/** The boxes of one screen from the $wp_meta_boxes slice for it: id => clean title, sorted by title. Pure. */
function devdadcl_metaboxes_catalog_from($screen_boxes)
{
    $out = array();
    if (!is_array($screen_boxes)) {
        return $out;
    }
    foreach ($screen_boxes as $context => $priorities) {
        if (!is_array($priorities)) {
            continue;
        }
        foreach ($priorities as $boxes) {
            if (!is_array($boxes)) {
                continue;
            }
            foreach ($boxes as $id => $box) {
                if (!is_array($box) || empty($box['id']) || empty($box['title'])) {
                    continue; // a removed box is `false`, a nameless one cannot be picked
                }
                $title = devdadcl_menus_clean_title($box['title']);
                if ($title !== '') {
                    $out[(string) $box['id']] = $title;
                }
            }
        }
    }
    asort($out);
    return $out;
}

/** Every id the meta box catalog can express: "post_type|box_id". Pure. */
function devdadcl_metaboxes_catalog_ids(array $catalog)
{
    $ids = array();
    foreach ($catalog as $post_type => $boxes) {
        foreach (is_array($boxes) ? $boxes : array() as $id => $title) {
            $ids[] = (string) $post_type . '|' . (string) $id;
        }
    }
    return $ids;
}

/** The posted picks, allowlisted like the menu picks (known roles, known ids). Pure. */
function devdadcl_metaboxes_sanitize($posted, array $catalog, array $allowed_roles)
{
    $ids = array_flip(devdadcl_metaboxes_catalog_ids($catalog));
    $out = array();
    foreach (is_array($posted) ? $posted : array() as $role => $list) {
        $role = sanitize_key((string) $role);
        if ($role === '' || !in_array($role, $allowed_roles, true) || !is_array($list)) {
            continue;
        }
        $keep = array();
        foreach ($list as $id) {
            $id = is_string($id) ? trim(wp_unslash($id)) : '';
            if ($id !== '' && isset($ids[$id])) {
                $keep[$id] = true;
            }
        }
        if ($keep) {
            $out[$role] = array_keys($keep);
        }
    }
    return $out;
}

/* ------------------------------ WordPress hooks (1.0.12) ------------------------------ */

/** Order: WordPress asks for a custom order, then for the order itself. For every user. */
function devdadcl_menu_order_wanted($custom)
{
    return devdadcl_get_array('menu_order', array()) ? true : $custom;
}
add_filter('custom_menu_order', 'devdadcl_menu_order_wanted');

function devdadcl_menu_order_filter($current)
{
    $saved = devdadcl_get_array('menu_order', array());
    return ($saved && is_array($current)) ? devdadcl_menu_order_sorted($saved, $current) : $current;
}
add_filter('menu_order', 'devdadcl_menu_order_filter');

/** Names: after the catalog snapshot (9998) and the hidden items (9999), so the catalog keeps the original words. */
function devdadcl_menu_names_apply()
{
    $map = devdadcl_menu_names_map(devdadcl_get_array('menu_names', array()));
    if (!$map || empty($GLOBALS['menu']) || !is_array($GLOBALS['menu'])) {
        return;
    }
    foreach ($GLOBALS['menu'] as $i => $m) {
        if (is_array($m) && isset($m[2]) && isset($map[(string) $m[2]])) {
            $GLOBALS['menu'][$i][0] = devdadcl_menu_title_renamed(isset($m[0]) ? $m[0] : '', $map[(string) $m[2]]);
        }
    }
}
add_action('admin_menu', 'devdadcl_menu_names_apply', 10000);

/** Snapshot the meta boxes of the editor screen an administrator opens (plugin boxes included). */
function devdadcl_metaboxes_capture($post_type)
{
    if (!current_user_can('manage_options') || !is_string($post_type) || $post_type === '' || !post_type_exists($post_type)) {
        return;
    }
    if (empty($GLOBALS['wp_meta_boxes'][$post_type])) {
        return;
    }
    $boxes = devdadcl_metaboxes_catalog_from($GLOBALS['wp_meta_boxes'][$post_type]);
    if (!$boxes) {
        return;
    }
    $stored = devdadcl_metaboxes_catalog();
    if (!isset($stored[$post_type]) || $stored[$post_type] !== $boxes) {
        $stored[$post_type] = $boxes;
        update_option('devdadcl_metabox_catalog', $stored, false);
    }
}
add_action('add_meta_boxes', 'devdadcl_metaboxes_capture', 9998, 1);

/** The stored meta box catalog: post_type => (box id => title). */
function devdadcl_metaboxes_catalog()
{
    $c = get_option('devdadcl_metabox_catalog', array());
    return is_array($c) ? $c : array();
}

/** Remove the picked boxes on a targeted user's editor. Never for option managers, only while Client Mode is on. */
function devdadcl_metaboxes_apply($post_type)
{
    if (!devdadcl_get_int('client_mode_on', 0) || current_user_can('manage_options') || !is_string($post_type) || $post_type === '') {
        return;
    }
    $user = wp_get_current_user();
    if (!$user || empty($user->roles)) {
        return;
    }
    $hidden = devdadcl_menus_hidden_for_roles(devdadcl_get_array('client_hidden_metaboxes', array()), $user->roles);
    foreach ($hidden as $id) {
        $parts = explode('|', (string) $id, 2);
        if (count($parts) === 2 && $parts[0] === $post_type) {
            foreach (array('normal', 'side', 'advanced') as $context) {
                remove_meta_box($parts[1], $post_type, $context);
            }
        }
    }
}
add_action('add_meta_boxes', 'devdadcl_metaboxes_apply', 9999, 1);
