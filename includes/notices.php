<?php
/**
 * Admin Notice Inbox + Snooze engine.
 *
 * Captures admin notices by output-buffering the four notice hooks: at a VERY LOW priority we
 * ob_start(); at a VERY HIGH priority we grab the buffer, split it into individual notice nodes,
 * compute a STABLE hash per notice (normalized whitespace), drop muted/snoozed ones, re-print the
 * rest, and record every seen notice into the stored inbox (option 'devdadcl_notices', autoload
 * NO, capped at 200).
 *
 * CRITICAL notices (notice-error class, or the words error/critical/security/expired) are classified
 * and shown as such in the inbox; Clean My Admin hides them too (owner rule: one click hides all) and
 * they stay one click from Restore.
 *
 * Per-notice actions run through admin-post.php (nonce + capability): keep visible, snooze 1 day,
 * snooze 7 days, hide forever, restore. Muted hashes + snooze-until live in 'devdadcl_muted'.
 */

defined('ABSPATH') || exit;

/** The notice hooks we capture, in render order. */
/** Diagnostic sink for the capture path (the test harness defines its own before this file loads). */
if (!function_exists('devdadcl_buffer_diagnostic')) {
    function devdadcl_buffer_diagnostic($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[devdome-admin-cleaner] ' . (string) $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug builds only
        }
    }
}

function devdadcl_notice_hooks()
{
    return array('admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices');
}

/* ----------------------------- capture ---------------------------------- */

/**
 * Hook the capture: open a buffer at priority -PHP_INT_MAX on each notice hook, and process it at
 * priority PHP_INT_MAX. Runs on every admin page (including our own) for users who can manage the
 * cleaner; each notice is recorded into the inbox and any muted/snoozed ones are dropped before the
 * survivors are re-echoed.
 */
function devdadcl_init_capture()
{
    if (!is_admin()) {
        return;
    }
    foreach (devdadcl_notice_hooks() as $hook) {
        add_action($hook, 'devdadcl_buffer_open', -PHP_INT_MAX);
        add_action($hook, 'devdadcl_buffer_close', PHP_INT_MAX);
    }
}
add_action('admin_init', 'devdadcl_init_capture');

/**
 * Open the capture buffer and record the level it occupies, so the close handler knows
 * exactly which buffer is ours.
 */
function devdadcl_buffer_open()
{
    // No buffer at all for users who will never see our UI: capturing output we are not
    // allowed to filter is pure risk with no benefit.
    if (!current_user_can(devdadcl_capability())) {
        return;
    }
    if (ob_start()) {
        $GLOBALS['devdadcl_buffer_levels'][] = ob_get_level();
    }
}

/**
 * Close the capture buffer: split into notices, record + filter them, then echo the survivors.
 * If anything is off we fail open (echo the original buffer untouched) so we never hide notices
 * by accident.
 *
 * Our buffer is ALWAYS closed here, never left open for the rest of the page. If another
 * component opened a buffer above ours during the notice hook and did not close it, that buffer
 * is flushed down into ours first (its bytes are kept, in order), then ours is closed.
 */
function devdadcl_buffer_close()
{
    if (empty($GLOBALS['devdadcl_buffer_levels'])) {
        return; // we never opened one
    }
    $owned = (int) array_pop($GLOBALS['devdadcl_buffer_levels']);
    if (ob_get_level() < $owned) {
        devdadcl_buffer_diagnostic('the capture buffer was already closed by another component; notices were passed through unfiltered');
        return; // nothing of ours is open
    }
    while (ob_get_level() > $owned) {
        $top = ob_get_status();
        if (empty($top) || !($top['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) || !ob_end_flush()) {
            devdadcl_buffer_diagnostic('a non-removable output buffer was open above ours; notices were passed through unfiltered');
            return; // PHP cannot close a buffer that sits under a locked one
        }
    }

    $html = ob_get_clean();
    if (!is_string($html) || $html === '') {
        return;
    }

    // Oversized output is passed straight through. Parsing is bounded work on
    // attacker-influenceable markup, and a slow admin page is a worse trade than an
    // unfiltered one.
    if (strlen($html) > DEVDADCL_MAX_BUFFER_BYTES) {
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oversized output, passed through unchanged.
        return;
    }

    $nodes = devdadcl_split_notices($html);
    if (empty($nodes)) {
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- could not parse into nodes; print original untouched.
        return;
    }

    $out     = '';
    $created = false;
    if (!isset($GLOBALS['devdadcl_created_hashes'])) {
        $GLOBALS['devdadcl_created_hashes'] = array(); // every notice hook of this request (Codex round 4: a per-buffer list let
    }                                                  // the second hook's retention evict the first hook's new record)
    $dirty   = false;

    foreach ($nodes as $entry) {
        // Filler is everything BETWEEN notices - scripts, styles, stray text a plugin
        // printed beside its notice. It is re-emitted byte for byte and never recorded,
        // classified, hashed or muted. It used to be appended as a pseudo-node, so a
        // <script> printed next to a notice appeared in the inbox as if it were one, and
        // could be "muted" - which silently dropped that script from the page.
        if ($entry['type'] !== 'notice') {
            $out .= $entry['html'];
            continue;
        }
        $node = $entry['html'];
        $id   = devdadcl_identity($node);
        $hash = $id['hash'];
        // ONE classification per node, carried into the stored record. Everything that later
        // decides whether this notice may be hidden reads that stored decision, so no code
        // path can reach a different conclusion about the same notice.
        $class = devdadcl_classify($node);

        // Record/refresh in the per-record store (touches only this notice's row, and only when something material
        // changed). A stored mute STAYS through a text or class change (owner 2026-09-14); the inbox shows the new class.
        if (function_exists('devdadcl_db_reset_error')) {
            devdadcl_db_reset_error();
        }
        $before  = devdadcl_get_record($hash);
        if (function_exists('devdadcl_db_failed') && devdadcl_db_failed()) {
            // The row could not be read: not "first seen". Nothing is written or hidden for this node; it is shown as
            // is (Codex round 6: a restored critical notice was re-hidden for every admin after a failed read).
            $out .= $node;
            continue;
        }
        $existed = ($before !== null);
        $landed  = devdadcl_store_capture($hash, $id['fingerprint'], $node, $class); // true only when the record write was proved
        if ($landed) {
            $dirty = true;
            if (!$existed) {
                $created = true;
                $GLOBALS['devdadcl_created_hashes'][] = $hash;
            }
        }
        // Auto-hide (owner 2026-09-14): with the switch on, a notice seen for the FIRST time goes straight to the inbox,
        // hidden for every admin. Only a record that LANDED gets its mute (Codex 2026-09-14: a mute without a record has no
        // Restore target); a mute that did not land is retried on the next capture through the record's pending flag.
        if (function_exists('devdadcl_get_int') && devdadcl_get_int('auto_hide_new', 0)) {
            $rec_now = devdadcl_get_record($hash);
            $pending = ($landed && !$existed) || ($rec_now !== null && !empty($rec_now['auto_hide_pending']));
            if ($pending && $rec_now !== null) {
                if (devdadcl_mute_set($hash, 0, 'admins') || devdadcl_mute_active_for_user($hash)) {
                    if (!empty($rec_now['auto_hide_pending'])) {
                        unset($rec_now['auto_hide_pending']);
                        devdadcl_put_record($hash, $rec_now);
                    }
                } elseif (empty($rec_now['auto_hide_pending'])) {
                    $rec_now['auto_hide_pending'] = 1;
                    devdadcl_put_record($hash, $rec_now);
                }
            }
        }

        // Visibility for the CURRENT user: their own mute or an all-admins mute. Users who
        // cannot use Admin Cleaner never reach this code (the buffer is not even opened).
        if (!devdadcl_mute_active_for_user($hash)) {
            $out .= $node;
        }
    }

    if ($created) {
        devdadcl_enforce_retention($GLOBALS['devdadcl_created_hashes']); // never the records this request just made
    }
    // Capture is a state change like any other: the cached summary must not keep saying
    // "0 notices" for up to 15 minutes after a notice was recorded (reproduced in the audit).
    if ($dirty && function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }

    echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- re-emitting the original WP/plugin notice markup for the surviving nodes, unchanged.
}

/**
 * Split a notice-area HTML blob into an ordered list of entries. Each entry is
 * array{type: 'notice'|'filler', html: string}.
 *
 * Filler is TYPED, not guessed at. Content around and between notices is returned as its
 * own 'filler' entry so the caller can re-emit it verbatim and never treat it as a notice.
 * Concatenating every entry's html reproduces the input byte for byte.
 *
 * @return array list of entries, or empty if nothing matched (caller then echoes the original).
 */
/**
 * Is this <div ...> opening tag a notice wrapper? The REAL class attribute is parsed (attribute by attribute), so a
 * class="notice" inside another attribute's value (title='class="notice"') or a data-class= never counts
 * (Codex round 7), and the token must be a complete whitespace-delimited class name.
 */
function devdadcl_tag_is_notice($tag)
{
    if (!preg_match('/^<div\b(.*)>$/is', trim((string) $tag), $m)) {
        return false;
    }
    if (!preg_match_all('/\s([a-zA-Z_:][-\w:.]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/', $m[1], $attrs, PREG_SET_ORDER)) {
        return false;
    }
    foreach ($attrs as $a) {
        if ('class' !== strtolower($a[1])) {
            continue;
        }
        $value = isset($a[2]) ? trim($a[2], '"\'') : '';
        foreach (preg_split('/\s+/', strtolower($value)) as $token) {
            if (in_array($token, array('notice', 'updated', 'error', 'update-nag'), true)) {
                return true;
            }
        }
        return false; // the first class attribute decides (browsers ignore a duplicate)
    }
    return false;
}

function devdadcl_split_notices($html)
{
    // Notice wrappers appear with double-quoted, single-quoted and unquoted class
    // attributes, and with attributes in any order. The old pattern required
    // class="..." specifically, so a plugin emitting class='notice notice-info' produced
    // ZERO recognised nodes - and the whole buffer was then appended as one pseudo-node,
    // which the caller went on to hash, classify and potentially mute.
    // A COMPLETE whitespace-delimited token: class="notice-board" is not a notice (Codex round 5: an ordinary form
    // wrapper was captured and hidden).
    $open_re = '/<div\b[^>]*(?<![\w-])class\s*=\s*(?:"(?:[^"]*\s)?(?:notice|updated|error|update-nag)(?:\s[^"]*)?"'
             . '|\'(?:[^\']*\s)?(?:notice|updated|error|update-nag)(?:\s[^\']*)?\''
             . '|(?:notice|updated|error|update-nag)(?=[\s>]))[^>]*>/i';

    if (!preg_match($open_re, $html)) {
        return array();
    }
    // Tokens come from a copy with every <script> / <style> body blanked to spaces (same length, same offsets): a
    // notice template inside JavaScript or a literal </div> in a string is text, not structure (Codex + DeepSeek
    // round 7: a script's template was emptied and a page lost its wrapper).
    $scan = preg_replace_callback('~<(script|style)\b[^>]*>.*?</\1\s*>~is', function ($m) {
        return str_repeat(' ', strlen($m[0]));
    }, $html);
    if (!is_string($scan) || strlen($scan) !== strlen($html)) {
        $scan = $html;
    }

    // Tokenize every <div ...> / </div> with byte offsets so we can balance nesting. A
    // non-greedy ".*?</div>" breaks the moment a notice contains an inner <div> (action
    // wrappers, dismiss buttons, multi-paragraph promos): it truncates the node,
    // mis-hashes it, and can leak a stray </div> that breaks the wp-admin layout. We walk
    // div depth to find each notice's REAL matching close. If anything is unbalanced we
    // fail open (caller echoes the buffer untouched).
    if (!preg_match_all('/<div\b[^>]*>|<\/div>/i', $scan, $tm, PREG_OFFSET_CAPTURE)) {
        return array();
    }
    $tags   = $tm[0];               // list of [tagHtml, byteOffset]
    $count  = count($tags);
    $nodes  = array();
    $cursor = 0;                    // bytes emitted so far (keeps output lossless)
    $i      = 0;

    while ($i < $count) {
        $tag = $tags[$i][0];
        $pos = $tags[$i][1];
        $is_close = (stripos($tag, '</div') === 0);

        if (!$is_close && devdadcl_tag_is_notice($tag)) {
            // Balance forward from this opening notice div to its matching close.
            $depth   = 0;
            $end_pos = -1;
            for ($j = $i; $j < $count; $j++) {
                if (stripos($tags[$j][0], '</div') === 0) {
                    $depth--;
                    if ($depth === 0) {
                        $end_pos = $tags[$j][1] + strlen($tags[$j][0]);
                        break;
                    }
                } else {
                    $depth++;
                }
            }
            if ($end_pos === -1) {
                return array(); // unbalanced - fail open, never risk a broken page
            }
            if ($pos > $cursor) {
                // Everything before this notice is filler. Emitted verbatim INCLUDING
                // whitespace: the contract is byte-for-byte output when nothing is muted.
                $nodes[] = array('type' => 'filler', 'html' => substr($html, $cursor, $pos - $cursor));
            }
            $nodes[] = array('type' => 'notice', 'html' => substr($html, $pos, $end_pos - $pos));
            $cursor  = $end_pos;
            $i       = $j + 1;     // skip past the matched close
            continue;
        }
        $i++;
    }

    $tail = substr($html, $cursor);
    if ($tail !== '') {
        $nodes[] = array('type' => 'filler', 'html' => $tail);
    }
    return $nodes;
}

// devdadcl_notice_hash() (md5 of class string + digit-collapsed text) was REPLACED by
// devdadcl_identity() in notice-store.php: canonicalized class tokens + wrapper id +
// digit-collapsed text for the stable hash, PLUS a digits-intact fingerprint so a
// materially different notice can be split from an old identity (audit batch 3).

// The old devdadcl_is_critical()/is_promo() pair lived here. They were REMOVED, not left
// alongside the new policy: is_promo() returned true for every update-nag and is_critical()
// missed it, which is exactly how a WordPress core update notice got muted forever. Leaving
// them in place would leave a second, weaker opinion for someone to call by mistake.
// The single source of truth is devdadcl_classify() in notice-policy.php.

/** Best-effort source detection (plugin/theme name) from a notice node. */
function devdadcl_detect_source($node)
{
    // Look for a recognizable id/class slug or a known plugin keyword in the text.
    if (preg_match('/(?:id|class)="[^"]*\b([a-z0-9]+(?:[-_][a-z0-9]+){0,3})[-_]notice\b/i', $node, $m)) {
        $slug = str_replace(array('-', '_'), ' ', $m[1]);
        return ucwords(trim($slug));
    }
    $text = wp_strip_all_tags($node);
    $known = array(
        'WooCommerce' => 'woocommerce',
        'Yoast SEO'   => 'yoast',
        'Elementor'   => 'elementor',
        'Jetpack'     => 'jetpack',
        'Contact Form 7' => 'contact form 7',
        'WPForms'     => 'wpforms',
        'Rank Math'   => 'rank math',
        'WordPress'   => 'wordpress',
    );
    foreach ($known as $label => $needle) {
        if (stripos($text, $needle) !== false) {
            return $label;
        }
    }
    return __('Unknown', 'devdome-admin-cleaner');
}

/* ----------------------------- storage ---------------------------------- */
// The whole-option inbox + mute-map stores that lived here were replaced by the per-record
// store in notice-store.php (audit batch 3): one option row per notice, one per mute, so
// concurrent administrators can no longer overwrite each other's records, retention can
// protect unresolved critical rows, and "newly muted" counts are true counts.

/**
 * Clean My Admin: hide EVERY notice the inbox knows (promo, unknown, protected alike) for ALL administrators
 * (owner 2026-09-14, hide-all rule; the inbox with its Critical badge is the way back).
 *
 * Returns the TRUE number of newly-created mutes: repeating the call changes nothing and
 * returns 0 (the audit reproduced the old count reporting 2 on both of two repeated calls).
 */
function devdadcl_mute_current_promos()
{
    // Owner 2026-09-14 ("hide all, most people want to clean it out with one click"): every notice the
    // inbox knows, whatever its class (promo, unknown, protected), hidden for ALL administrators.
    // The classifier still labels rows (the inbox shows "Critical") and every row can be restored there.
    $count = 0;
    $GLOBALS['devdadcl_mute_failed'] = 0;
    foreach (devdadcl_get_inbox() as $hash => $rec) {
        if (devdadcl_mute_set($hash, 0, 'admins')) { // hidden until restored, for every admin (proved write)
            $count++;
        } else {
            $m = devdadcl_mute_get($hash);
            if ($m === null || 0 !== $m['admins']) {
                $GLOBALS['devdadcl_mute_failed']++; // not already hidden for every admin forever and the write did not land (Codex rounds 1-2)
            }
        }
    }
    return $count;
}

/* ----------------------------- admin-post actions ----------------------- */

/**
 * Single admin-post handler for every per-notice action. Nonce + cap guarded, then PRG back to the
 * Notice Inbox tab. Action set: keep, snooze1, snooze7, hide (current user), hide_admins
 * (all administrators — its button carries its own confirmation), restore, delete.
 *
 * Scope model (audit batch 3): ordinary snooze/hide affects only the CURRENT USER. Hiding a
 * notice for every administrator is a separate, explicit action. Users who cannot use Admin
 * Cleaner are never affected by any of these (capture does not run for them).
 */
function devdadcl_handle_notice_action()
{
    if (!current_user_can(devdadcl_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-admin-cleaner'));
    }
    check_admin_referer('devdadcl_notice', '_acnonce');
    devdadcl_db_guard_begin(); // guard window (DESIGN.md 24)

    $hash = isset($_POST['hash']) ? sanitize_text_field(wp_unslash($_POST['hash'])) : '';
    $do   = isset($_POST['do']) && is_string($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
    if ('delete' === $do && (!isset($_POST['ac_confirmed']) || '1' !== sanitize_text_field(wp_unslash($_POST['ac_confirmed'])))) {
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'inbox', 'ac_error' => 'confirm')); // Remove is the one inbox action that asks (Codex round 7)
    }

    $changed = devdadcl_notice_apply($hash, $do);
    if ($changed !== null && function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary(); // the cached summary reflects the change at once (UI + hub)
    }
    if (is_wp_error($changed)) {
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'inbox', 'ac_error' => 'save'));
    }
    devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'inbox', 'ac_done' => (true === $changed) ? '1' : '0'));
}
add_action('admin_post_devdadcl_notice', 'devdadcl_handle_notice_action');

/**
 * One notice action, shared by the single-row handler and the bulk handler (the Notice Inbox actions
 * menu). Only known hashes are touched. Returns true when the state changed, false when it was already in
 * that state (or the action is unknown), null when the hash is unknown.
 */
function devdadcl_notice_apply($hash, $do)
{
    $rec = ($hash === '') ? null : devdadcl_get_record($hash);
    if ($rec === null) {
        return null;
    }
    // The screen greys snooze/hide on a hidden row and Restore on a visible one; the server enforces the same
    // (Codex round 2: a direct request could swap a permanent mute for a one-day snooze). A write that did not land
    // is a WP_Error, never "already in that state" (DeepSeek round 2).
    $failed = new WP_Error('devdadcl_write_failed', __('The change could not be saved: the database did not keep it.', 'devdome-admin-cleaner'));
    $hidden = devdadcl_mute_active_for_user($hash);
    switch ($do) {
        case 'snooze1':
        case 'snooze7':
        case 'hide':
        case 'hide_admins':
            // hide_admins is the stronger action: a personal mute must not block it (DeepSeek round 6); the others
            // change nothing on a row already hidden for this user.
            if ('hide_admins' === $do ? devdadcl_mute_active_for_admins($hash) : $hidden) {
                return false;
            }
            $until = ('snooze1' === $do) ? time() + DAY_IN_SECONDS : (('snooze7' === $do) ? time() + 7 * DAY_IN_SECONDS : 0);
            if (devdadcl_mute_set($hash, $until, 'hide_admins' === $do ? 'admins' : 'user')) {
                return true;
            }
            return ('hide_admins' === $do ? devdadcl_mute_active_for_admins($hash) : devdadcl_mute_active_for_user($hash)) ? false : $failed;
        case 'keep':
            // keep = "leave it visible": only a pending auto-hide is dropped, never a mute (DeepSeek round 6: keep used
            // to restore a row hidden for every admin).
            if (empty($rec['auto_hide_pending'])) {
                return false;
            }
            unset($rec['auto_hide_pending']);
            return devdadcl_put_record($hash, $rec) ? true : $failed;
        case 'restore':
            // Restore un-hides for EVERYONE: your own mute and any all-admins mute, and drops a pending auto-hide
            // (Codex round 2: a pending marker re-hid the notice on the next capture). Only a row hidden for THIS user
            // (or pending) can be restored, as the screen greys it otherwise (Codex round 4: a visible row's mute that
            // belonged to another user was cleared).
            if (!$hidden && empty($rec['auto_hide_pending'])) {
                return false;
            }
            $had_mute = devdadcl_mute_get($hash) !== null;
            $cleared  = $had_mute ? (bool) devdadcl_mute_clear($hash, 'all') : false;
            if ($had_mute && !$cleared && devdadcl_mute_get($hash) !== null) {
                return $failed;
            }
            if (!empty($rec['auto_hide_pending'])) {
                unset($rec['auto_hide_pending']);
                if (!devdadcl_put_record($hash, $rec)) {
                    return $failed;
                }
                return true;
            }
            return $cleared;
        case 'delete':
            // Full forget: the mute row goes FIRST, then the record (Codex round 2: a record dropped before a failed
            // mute delete left an orphan mute that a retry called "unknown hash"). If the source plugin still emits
            // the notice it re-appears as a fresh row on the next admin load.
            if (devdadcl_mute_get($hash) !== null) {
                devdadcl_mute_clear($hash, 'all');
                if (devdadcl_mute_get($hash) !== null) {
                    return $failed;
                }
            }
            return devdadcl_delete_record($hash) ? true : $failed; // proved: "deleted" only when the row is gone (DESIGN.md 24.5)
    }
    return false;
}

/**
 * Bulk action from the Notice Inbox actions menu (checkboxes + Actions + Apply, the suite list standard):
 * one action for every selected hash, nonce + cap enforced here, PRG back to the inbox with "n of m".
 */
function devdadcl_handle_notice_bulk()
{
    if (!current_user_can(devdadcl_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-admin-cleaner'));
    }
    check_admin_referer('devdadcl_notice_bulk', '_acnonce');
    devdadcl_db_guard_begin(); // guard window (DESIGN.md 24)

    $do     = isset($_POST['do']) && is_string($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
    if ('delete' === $do && (!isset($_POST['ac_confirmed']) || '1' !== sanitize_text_field(wp_unslash($_POST['ac_confirmed'])))) {
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'inbox', 'ac_error' => 'confirm')); // Remove is the one inbox action that asks (Codex round 7)
    }
    $hashes = isset($_POST['hashes']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['hashes'])) : array(); // a nested array sanitizes to '' and is dropped below
    $hashes = array_values(array_unique(array_filter(array_map('strval', $hashes), 'strlen')));
    $total  = count($hashes);
    $done   = 0;
    $failed = 0;
    if (in_array($do, array('snooze1', 'snooze7', 'hide', 'hide_admins', 'restore', 'delete'), true)) {
        foreach ($hashes as $hash) {
            $r = devdadcl_notice_apply($hash, $do);
            if ($r === true) {
                $done++;
            } elseif (is_wp_error($r)) {
                $failed++;
            }
        }
        if ($total > 0 && function_exists('devdadcl_refresh_summary')) {
            devdadcl_refresh_summary();
        }
    }
    if ($failed > 0) {
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'inbox', 'ac_error' => 'save')); // a write that did not land is never "n of m updated"
    }

    devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'inbox', 'ac_bulk' => $done . '/' . $total));
}
add_action('admin_post_devdadcl_notice_bulk', 'devdadcl_handle_notice_bulk');

/**
 * "Delete all notice data" — the privacy purge (audit batch 3). Distinct from Reset
 * settings: removes every stored notice record and mute, nothing else.
 */
function devdadcl_handle_purge_notices()
{
    if (!current_user_can(devdadcl_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-admin-cleaner'));
    }
    check_admin_referer('devdadcl_purge_notices', '_acnonce');
    devdadcl_db_guard_begin(); // guard window (DESIGN.md 24)
    // DESIGN.md 23: the typed word travels with the form (ac_word, set by ac-admin.js) and is checked HERE, not only in
    // the browser (DeepSeek round 6).
    $word = isset($_POST['ac_word']) && is_string($_POST['ac_word']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['ac_word'])))) : '';
    if ('DELETE' !== $word) {
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'inbox', 'ac_error' => 'confirm'));
    }

    $n = devdadcl_purge_notice_data();
    if (function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    if (!empty($GLOBALS['devdadcl_purge_failed'])) {
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'inbox', 'ac_error' => 'save')); // rows stayed: never "every mute" (Codex + DeepSeek round 3)
    }
    devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'inbox', 'ac_purged' => (int) $n));
}
add_action('admin_post_devdadcl_purge_notices', 'devdadcl_handle_purge_notices');

/* ----------------------------- lifecycle hooks --------------------------- */

// Schema-1 → schema-2 migration, once, early on admin requests (before capture runs).
add_action('admin_init', 'devdadcl_migrate_legacy', 1);
// Daily housekeeping alongside the summary cron: expired snoozes, orphan mutes, retention.
add_action('devdadcl_summary_refresh', 'devdadcl_gc', 5);
add_action('devdadcl_summary_refresh', 'devdadcl_enforce_retention', 6);

/* ----------------------------- protected-hide safeguards ----------------- */

/** Hashes of protected records that currently have ANY active mute. */
function devdadcl_hidden_protected_hashes()
{
    $out = array();
    foreach (devdadcl_option_keys('devdadcl_m_') as $name) {
        $hash = substr($name, strlen('devdadcl_m_'));
        $rec  = devdadcl_get_record($hash);
        if ($rec === null || empty($rec['is_critical'])) {
            continue;
        }
        $m = devdadcl_mute_get($hash);
        if ($m === null) {
            continue;
        }
        $active = devdadcl_until_active($m['admins']);
        if (!$active) {
            foreach ($m['users'] as $until) {
                if (devdadcl_until_active($until)) {
                    $active = true;
                    break;
                }
            }
        }
        if ($active) {
            $out[] = $hash;
        }
    }
    return $out;
}

/**
 * Persistent "protected notices are hidden" banner (audit batch 1, per-row hide UX):
 * whenever any protected notice is hidden, every capable administrator sees one compact
 * banner with a one-click restore. Deliberately NOT built on the notice/updated/error
 * wrapper classes — our own capture parser must treat it as filler, never record it.
 */
function devdadcl_protected_hidden_banner()
{
    // Owner 2026-09-14: a hidden notice must not spawn another notice. The inbox (and its Critical badge)
    // is the way back; the restore-all handler below stays for the inbox. Banner off.
    return;
    if (!current_user_can(devdadcl_capability())) { // phpcs:ignore Squiz.PHP.NonExecutableCode.Unreachable -- kept for a possible opt-in later
        return;
    }
    $hidden = devdadcl_hidden_protected_hashes();
    if (!$hidden) {
        return;
    }
    $count = count($hidden);
    ?>
    <div class="dd-ac-protected-banner" style="margin:12px 20px 8px 2px;padding:10px 14px;border:1px solid #f59e0b;border-left-width:4px;border-radius:4px;background:#fffbeb;color:#92400e;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span>
            <?php
            /* translators: %d is the number of hidden protected notices. */
            printf(esc_html(_n('%d protected operational notice is currently hidden by Admin Cleaner.', '%d protected operational notices are currently hidden by Admin Cleaner.', $count, 'devdome-admin-cleaner')), (int) $count);
            ?>
        </span>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
            <input type="hidden" name="action" value="devdadcl_restore_protected">
            <?php wp_nonce_field('devdadcl_restore_protected', '_acnonce'); ?>
            <button type="submit" class="button button-small"><?php esc_html_e('Restore them all', 'devdome-admin-cleaner'); ?></button>
        </form>
        <a href="<?php echo esc_url(admin_url('admin.php?page=' . DEVDADCL_PAGE . '&ac_tab=inbox')); ?>"><?php esc_html_e('Review in the Notice Inbox', 'devdome-admin-cleaner'); ?></a>
    </div>
    <?php
}
add_action('all_admin_notices', 'devdadcl_protected_hidden_banner', -PHP_INT_MAX + 1);

/** One-click restore of EVERY hidden protected notice. */
function devdadcl_handle_restore_protected()
{
    if (!current_user_can(devdadcl_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-admin-cleaner'));
    }
    check_admin_referer('devdadcl_restore_protected', '_acnonce');
    devdadcl_db_guard_begin(); // guard window (DESIGN.md 24)

    $n = 0;
    $failed = 0;
    foreach (devdadcl_hidden_protected_hashes() as $hash) {
        if (devdadcl_mute_clear($hash, 'all')) {
            $n++;
        } elseif (devdadcl_mute_get($hash) !== null) {
            $failed++; // still hidden: never a success flag on a partial restore (DeepSeek round 3)
        }
    }
    if (function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    if ($failed > 0) {
        devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'inbox', 'ac_error' => 'save'));
    }
    devdadcl_finish(array('page' => DEVDADCL_PAGE, 'ac_tab' => 'inbox', 'ac_done' => $n ? '1' : '0'));
}
add_action('admin_post_devdadcl_restore_protected', 'devdadcl_handle_restore_protected');

/* ----------------------------- late notices (JavaScript-inserted) ------- */

/**
 * A notice a plugin inserted with JavaScript after the page was built never passed the capture. The admin-wide
 * script (assets/ac-late.js, loaded while Auto-hide is on) hides it and posts its markup here: it is recorded like a
 * captured notice (classified, redacted, proved) and hidden for every admin, unless the owner had restored exactly this
 * notice, in which case the script is told to show it. @return array{show:bool,hash:string,recorded:bool}
 */
function devdadcl_late_notice_apply($html, $screen = '')
{
    $html = (string) $html;
    // Only a real notice container: a <div> whose class carries notice / updated / error / update-nag. An <input
    // class="error"> or any other element is not a notice and is never hidden (Codex round 3).
    if ('' === trim($html) || strlen($html) > 16384
        || !preg_match('/^\s*(<div\b[^>]*>)/i', $html, $open) || !devdadcl_tag_is_notice($open[1])) {
        return array('show' => true, 'hash' => '', 'recorded' => false);
    }
    $id    = devdadcl_identity($html);
    $hash  = $id['hash'];
    $class = devdadcl_classify($html);
    $rec   = devdadcl_get_record($hash);
    if ($rec !== null) {
        // Known notice: its stored state decides. A one-day personal snooze stays a one-day personal snooze (Codex
        // round 2: every known late notice was escalated to a permanent all-admin mute); a notice with no mute and
        // no pending auto-hide was restored or seen before Auto-hide was on, and the owner's decision stands.
        devdadcl_store_capture($hash, $id['fingerprint'], $html, $class);
        if (devdadcl_mute_active_for_user($hash)) {
            return array('show' => false, 'hash' => $hash, 'recorded' => true);
        }
        if (empty($rec['auto_hide_pending'])) {
            return array('show' => true, 'hash' => $hash, 'recorded' => true);
        }
    }
    $created = ($rec === null);
    $landed  = devdadcl_store_capture($hash, $id['fingerprint'], $html, $class) || devdadcl_get_record($hash) !== null;
    if (!$landed) {
        return array('show' => true, 'hash' => $hash, 'recorded' => false); // no record = no Restore target: fail visible
    }
    if ($created) {
        devdadcl_enforce_retention(array($hash)); // same cap as the server capture (DeepSeek round 2), never this record
    }
    $hidden  = devdadcl_mute_set($hash, 0, 'admins') || devdadcl_mute_active_for_user($hash);
    $rec_now = devdadcl_get_record($hash);
    if ($rec_now !== null) {
        // The same pending marker the server capture keeps: a mute that did not land is retried, a mute that landed
        // clears it (Codex round 2: a failed late mute was read as "restored" on the next request).
        if ($hidden && !empty($rec_now['auto_hide_pending'])) {
            unset($rec_now['auto_hide_pending']);
            devdadcl_put_record($hash, $rec_now);
        } elseif (!$hidden && empty($rec_now['auto_hide_pending'])) {
            $rec_now['auto_hide_pending'] = 1;
            devdadcl_put_record($hash, $rec_now);
        }
    }
    if (function_exists('devdadcl_refresh_summary')) {
        devdadcl_refresh_summary();
    }
    return array('show' => !$hidden, 'hash' => $hash, 'recorded' => true);
}

function devdadcl_handle_late_notice()
{
    if (!current_user_can(devdadcl_capability())) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer('devdadcl_late', 'nonce');
    if (!devdadcl_get_int('auto_hide_new', 0)) {
        wp_send_json_success(array('show' => true)); // the switch went off meanwhile: show
    }
    devdadcl_db_guard_begin();
    $html   = isset($_POST['html']) && is_string($_POST['html']) ? wp_unslash($_POST['html']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the markup is classified, redacted and stored as an excerpt only
    $screen = isset($_POST['screen']) && is_string($_POST['screen']) ? sanitize_text_field(wp_unslash($_POST['screen'])) : '';
    $r = devdadcl_late_notice_apply($html, $screen);
    if (devdadcl_db_guard_active()) {
        $r['show'] = true; // a failed query = nothing trusted: show the notice rather than hide it without a record
    }
    devdadcl_db_guard_end();
    wp_send_json_success(array('show' => (bool) $r['show'], 'hash' => $r['hash']));
}
add_action('wp_ajax_devdadcl_late_notice', 'devdadcl_handle_late_notice');

/** The admin-wide late-notice script, only while Auto-hide is on (every admin screen, every capable user). */
function devdadcl_enqueue_late_script()
{
    if (!devdadcl_get_int('auto_hide_new', 0) || !current_user_can(devdadcl_capability())) {
        return;
    }
    $js = DEVDADCL_DIR . 'assets/ac-late.js';
    wp_enqueue_script('devdadcl-late', DEVDADCL_URL . 'assets/ac-late.js', array(), file_exists($js) ? filemtime($js) : DEVDADCL_VERSION, true);
    $screen = function_exists('get_current_screen') && get_current_screen() ? (string) get_current_screen()->id : '';
    wp_localize_script('devdadcl-late', 'devdadcl_late', array(
        'ajax'   => admin_url('admin-ajax.php'),
        'nonce'  => wp_create_nonce('devdadcl_late'),
        'screen' => $screen,
    ));
}
add_action('admin_enqueue_scripts', 'devdadcl_enqueue_late_script');

/* ----------------------------- admin-bar bell --------------------------- */

/**
 * Admin-bar "bell" node showing the muted-notice count, linking to the Notice Inbox tab. No JS —
 * just a server-rendered count. Honors the show_bell setting + capability.
 */
function devdadcl_admin_bar_bell($wp_admin_bar)
{
    if (!devdadcl_get_int('show_bell', 1) || !current_user_can(devdadcl_capability())) {
        return;
    }
    $s     = devdadcl_hub_summary();
    $muted = (int) $s['muted_count'];
    if (0 === $muted) {
        return; // a zero-count bell is itself clutter (audit batch 8)
    }
    $title = sprintf(
        /* translators: %d is the number of muted notices. */
        _n('%d muted notice', '%d muted notices', $muted, 'devdome-admin-cleaner'),
        $muted
    );
    $wp_admin_bar->add_node(array(
        'id'    => 'devdadcl-bell',
        'title' => '<span class="ab-icon dashicons dashicons-hidden" style="font-family:dashicons;top:2px;"></span><span class="ab-label">' . esc_html($muted) . '</span>',
        'href'  => admin_url('admin.php?page=' . DEVDADCL_PAGE . '&ac_tab=inbox'),
        'meta'  => array('title' => $title),
    ));
}
add_action('admin_bar_menu', 'devdadcl_admin_bar_bell', 990);
