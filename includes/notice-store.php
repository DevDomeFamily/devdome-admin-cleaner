<?php
/**
 * Notice store — versioned identity, redaction, per-record atomic storage, scoped mutes,
 * retention and legacy migration. (Audit 3, batch 3.)
 *
 * STORAGE MODEL: one option row PER RECORD, never a whole-inbox array.
 *   devdadcl_n_<hash>  — one seen notice (autoload no)
 *   devdadcl_m_<hash>  — that notice's mute state (autoload no)
 * The invariant with teeth (learned on Backup & Migration batch 8): writing one item must
 * not rewrite another item's storage slot. Two administrators muting two different notices
 * concurrently can no longer overwrite each other, because they touch different rows. The
 * old model was three read-modify-write option arrays; the audit reproduced lost records
 * and a "newly muted" count that repeated on every call.
 *
 * IDENTITY (v2): the primary hash stays digit-collapsed so a live countdown doesn't mint a
 * new identity every render — but each record also stores a content FINGERPRINT with the
 * digits intact. When a notice's fingerprint or classification changes, the record is refreshed
 * (the inbox shows the new class, e.g. Critical) but a stored mute STAYS: the owner's rule
 * (2026-09-14) is that a hidden notice stays hidden when its text changes.
 *
 * SCOPE: a mute is explicitly per-user or all-admins. Ordinary snooze/hide defaults to the
 * CURRENT USER; all-admins requires its own explicit action. Users who cannot use Admin
 * Cleaner are never affected by either (capture doesn't run for them at all).
 *
 * PRIVACY: excerpts pass through devdadcl_redact() before persistence — emails, tokens,
 * nonces, query strings and filesystem paths never reach the database. Raw notice HTML is
 * never stored.
 */

defined('ABSPATH') || exit;

/** Storage schema version (stored in option devdadcl_schema). */
const DEVDADCL_SCHEMA_VERSION = 2;

/** Identity algorithm version, stored per record. */
const DEVDADCL_IDENTITY_VERSION = 3;

/** Retention: keep at most this many notice records (protected unresolved records exempt). */
const DEVDADCL_MAX_RECORDS = 200;

/** Option-name prefixes for the per-record rows. Literal at every call site below. */
const DEVDADCL_REC_PREFIX  = 'devdadcl_n_';
const DEVDADCL_MUTE_PREFIX = 'devdadcl_m_';

/* ------------------------------------------------------------------ enumeration */

if (!function_exists('devdadcl_option_keys')) {
    /**
     * All option names starting with $prefix. $wpdb LIKE scan (same pattern as Backup &
     * Migration's per-record store). The test harness defines its own copy before this
     * file loads — that is why the declaration is function_exists-guarded.
     *
     * @return string[]
     */
    function devdadcl_option_keys($prefix)
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return array();
        }
        $like = $wpdb->esc_like($prefix) . '%';
        // Bracketed (DESIGN.md 24): a failed SELECT is recorded by the guard (every action window and the page render
        // then answer a database error) instead of passing as "no records" (DeepSeek 2026-09-14).
        if (function_exists('devdadcl_db_reset_error')) {
            devdadcl_db_reset_error();
        }
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
        if (function_exists('devdadcl_db_failed') && devdadcl_db_failed()) {
            $GLOBALS['devdadcl_option_keys_failed'] = true;
            return array();
        }
        $GLOBALS['devdadcl_option_keys_failed'] = false;
        return is_array($names) ? $names : array();
    }
}

/* ------------------------------------------------------------------ identity v2 */

/**
 * Canonical class-token signature: tokens sorted so order never churns identity, tokens
 * carrying digits dropped (dynamic ids like notice-5f3a churn identity; the stable tokens
 * carry the meaning).
 */
function devdadcl_canonical_classes($node)
{
    $raw = devdadcl_attr_values($node, 'class');
    $tokens = preg_split('/\s+/', strtolower(trim($raw)), -1, PREG_SPLIT_NO_EMPTY);
    $keep = array();
    foreach ($tokens as $t) {
        if (!preg_match('/\d/', $t)) {
            $keep[$t] = 1;
        }
    }
    ksort($keep);
    return implode(' ', array_keys($keep));
}

/**
 * Identity for one notice node.
 *
 * @return array{hash:string, fingerprint:string}
 *   hash        — stable identity (digit runs collapsed; class tokens canonicalized;
 *                 wrapper id included). What mutes and records key on.
 *   fingerprint — digits preserved; detects a materially different notice reusing the
 *                 same identity (see devdadcl_store_capture()).
 */
function devdadcl_identity($node)
{
    $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($node)));
    $id   = strtolower(trim(devdadcl_attr_values($node, 'id')));
    $cls  = devdadcl_canonical_classes($node);

    // v3 (DeepSeek 2026-09-14): a whole token that carries a digit (nonce-5f3a, 20260914, 3-days, abc123def) collapses to
    // '#', in the text AND the id attribute, so a notice whose ids or counters change on every load keeps one hash and its mute.
    $stable    = preg_replace('/[A-Za-z0-9_-]*\d[A-Za-z0-9_-]*/', '#', $text);
    $stable_id = preg_replace('/[A-Za-z0-9_-]*\d[A-Za-z0-9_-]*/', '#', $id);
    return array(
        'hash'        => md5('v3|' . $cls . '|' . $stable_id . '|' . $stable),
        'fingerprint' => md5($cls . '|' . $id . '|' . $text),
    );
}

/* ------------------------------------------------------------------ redaction */

/**
 * Strip values that must never be persisted from an excerpt. Order matters: URLs first
 * (their query strings hold most of the tokens), then freestanding secrets.
 */
function devdadcl_redact($text)
{
    $text = (string) $text;
    // Entities first: password=&quot;x&quot; is the same secret as password="x" (Codex round 2). The excerpt is
    // escaped again at every output.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // PEM blocks and define()d / putenv()d secrets first, while the text still has its line structure (Codex 2026-09-14).
    $text = preg_replace('/-----BEGIN [A-Z ]+-----.*?-----END [A-Z ]+-----/s', '[redacted]', $text);
    // A quoted value ends at ITS OWN delimiter; the other quote and escaped quotes are part of the value (Codex round 3:
    // define('DB_PASSWORD', 'alpha" beta') and password="alpha\" beta" both survived).
    $text = preg_replace('/define\s*\(\s*([\'"])[^\'"]*(?:KEY|SECRET|TOKEN|PASS|SALT|NONCE|AUTH)[^\'"]*\1\s*,\s*([\'"])(?:\\\\.|(?!\2).)*\2\s*\)/is', 'define([redacted])', $text);
    $text = preg_replace('/putenv\s*\(\s*([\'"])(?:\\\\.|(?!\1).)*\1\s*\)/is', 'putenv([redacted])', $text);
    // HTTP credentials: "Authorization: Basic dXNlcjpwYXNz" / "Bearer eyJ..." (Codex round 8: a 12-char Basic value slipped past the 20+ rule).
    $text = preg_replace('/\b(authorization|proxy-authorization|x-api-key|x-auth-token)\s*[:=]\s*(?:(?:basic|bearer|digest)\s+)?[^\s,;"\']+/i', '$1: [redacted]', $text);
    $text = preg_replace('/\b(basic|bearer|digest)\s+[A-Za-z0-9+\/=._~-]{8,}/i', '$1 [redacted]', $text);
    // URL userinfo (https://user:pass@host), then the query/fragment (signed URLs, nonces, tokens).
    $text = preg_replace('/(https?:\/\/)[^\s\/@"\']+@/i', '$1[redacted]@', $text);
    $text = preg_replace('/(https?:\/\/[^\s?#"\']+)[?#][^\s"\']*/i', '$1', $text);
    // Quoted key=value secrets (spaces inside the quotes), before the bare form below.
    // The key may carry a prefix or suffix (DB_PASSWORD, $api_key, wp_auth_token): the secret word anywhere in it counts.
    // A quoted key ("password": "x", 'api_key' => 'y') counts too (Codex round 4: JSON in a notice kept both values).
    $text = preg_replace('/((?:[A-Za-z0-9_.$-]*)(?:api[_-]?key|token|secret|pass(?:word|wd)?|nonce|licen[cs]e[_-]?key|auth)(?:[_-][A-Za-z0-9_-]*)?)["\']?\s*(?:=>|[:=])\s*(["\'])(?:\\\\.|(?!\2).)*\2/is', '$1=[redacted]', $text);
    // key=value secrets, bare (api_key=, token:, secret =, password, nonce, licence/license).
    $text = preg_replace('/((?:[A-Za-z0-9_.$-]*)(?:api[_-]?key|token|secret|pass(?:word|wd)?|nonce|licen[cs]e[_-]?key|auth)(?:[_-][A-Za-z0-9_-]*)?)["\']?\s*(?:=>|[:=])\s*[^\s,;"\']+/i', '$1=[redacted]', $text); // => before = (Codex round 5: "=" ate the ">")
    // Email addresses.
    $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text);
    // Filesystem paths, Windows and POSIX.
    $text = preg_replace('~(?:[A-Za-z]:[\\\\/]|\\\\\\\\[A-Za-z0-9_.-]+\\\\|/(?:var|home|srv|etc|usr|tmp|opt|mnt|root|data|www|app|sites)/)[^\s"\':]*~', '[path]', $text); // C:/ too (Codex round 6)
    $text = preg_replace('~(?<![\w:/.])/[A-Za-z0-9_-]+\.(?:php|ini|log|txt|json|sql|env|conf|pem|key|htaccess)\b~i', '[path]', $text); // a root-level file (Codex round 5)
    $text = preg_replace('~(?<![\w:/.])/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+~', '[path]', $text); // any other absolute POSIX path (/web/a.php, custom document roots; Codex round 8)
    $text = preg_replace('~(?<![\w:/.])(?:wp-content|wp-includes|wp-admin|plugins|themes|uploads|mu-plugins)/[^\s"\'<>]+~i', '[path]', $text); // relative site paths (DeepSeek round 7)
    // Freestanding long token-ish runs (hex, base64ish, 20+ chars).
    $text = preg_replace('/\b[A-Za-z0-9+\/_\-]{20,}={0,2}\b/', '[redacted]', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

/** Build the stored (redacted, truncated) excerpt for a node. Raw HTML is never stored. */
function devdadcl_excerpt($node)
{
    $excerpt = devdadcl_redact(trim(wp_strip_all_tags($node)));
    return function_exists('mb_substr') ? mb_substr($excerpt, 0, 160) : substr($excerpt, 0, 160);
}

/* ------------------------------------------------------------------ record rows */

/** Read one notice record, or null. */
function devdadcl_get_record($hash)
{
    $v = get_option('devdadcl_n_' . $hash, null);
    return is_array($v) ? $v : null; // a failed read answers null = "absent": callers that delete on absence prove it with devdadcl_option_row()
}

/** Write one notice record (touches only this record's row). */
function devdadcl_put_record($hash, $rec)
{
    return devdadcl_option_write('devdadcl_n_' . $hash, $rec, false); // proved (DESIGN.md 24.5), never autoloaded
}

/** Delete one notice record. @return bool true only when the row is gone. */
function devdadcl_delete_record($hash)
{
    return devdadcl_option_delete('devdadcl_n_' . $hash);
}

/**
 * The whole inbox as hash => record. Enumerated from the per-record rows; shape-checked so
 * a corrupted row degrades to "skipped", never a fatal.
 */
function devdadcl_get_inbox()
{
    $inbox = array();
    $names = devdadcl_option_keys('devdadcl_n_');
    if (function_exists('devdadcl_db_reset_error')) {
        devdadcl_db_reset_error();
    }
    $read_failed = false;
    foreach ($names as $name) {
        $hash = substr($name, strlen('devdadcl_n_'));
        $rec  = devdadcl_get_record($hash);
        if (function_exists('devdadcl_db_failed') && devdadcl_db_failed()) {
            $read_failed = true; // checked per row: $wpdb->last_error is reset by the next query (Codex round 6)
            devdadcl_db_reset_error();
        }
        if ($rec !== null && isset($rec['hash'])) {
            $inbox[$hash] = $rec;
        }
    }
    if ($read_failed) {
        $GLOBALS['devdadcl_option_keys_failed'] = true; // a row that could not be read is not "no row" (DeepSeek round 5)
    }
    return $inbox;
}

/* ------------------------------------------------------------------ mute rows */

/**
 * One notice's mute state:
 *   array{
 *     admins: int|null,          // null = no all-admin mute; 0 = forever; ts = snoozed until
 *     users:  array<int,int>,    // uid => until (0 = forever)
 *     actor:  int, ts: int,      // who last changed this row, when
 *     policy_version: int,       // classifier version in force when the mute was made
 *   }
 */
function devdadcl_mute_get($hash)
{
    $v = get_option('devdadcl_m_' . $hash, null);
    if (!is_array($v)) {
        return null;
    }
    $v += array('admins' => null, 'users' => array(), 'actor' => 0, 'ts' => 0, 'policy_version' => 0);
    return $v;
}

/**
 * Set a mute. $scope 'user' (default) or 'admins'. $until 0 = forever, else snooze-until ts.
 * Returns true when the row actually changed (the true "newly muted" signal).
 */
function devdadcl_mute_set($hash, $until, $scope = 'user', $uid = null)
{
    $uid  = ($uid === null) ? get_current_user_id() : (int) $uid;
    $rec  = devdadcl_mute_get($hash);
    $rec  = ($rec === null) ? array('admins' => null, 'users' => array(), 'actor' => 0, 'ts' => 0, 'policy_version' => 0) : $rec;
    $until = (int) $until;

    if ($scope === 'admins') {
        if ($rec['admins'] === $until) {
            return false;
        }
        $rec['admins'] = $until;
    } else {
        if (isset($rec['users'][$uid]) && (int) $rec['users'][$uid] === $until) {
            return false;
        }
        $rec['users'][$uid] = $until;
    }
    $rec['actor'] = $uid;
    $rec['ts']    = time();
    $rec['policy_version'] = defined('DEVDADCL_POLICY_VERSION') ? DEVDADCL_POLICY_VERSION : 0;
    return devdadcl_option_write('devdadcl_m_' . $hash, $rec, false); // "newly muted" only when the row holds it (DESIGN.md 24.5)
}

/**
 * Clear mute state. $scope: 'user' (current user's entry), 'admins', or 'all'.
 * Returns true when something was removed.
 */
function devdadcl_mute_clear($hash, $scope = 'all', $uid = null)
{
    $rec = devdadcl_mute_get($hash);
    if ($rec === null) {
        return false;
    }
    $uid = ($uid === null) ? get_current_user_id() : (int) $uid;
    $changed = false;
    if (($scope === 'all' || $scope === 'admins') && $rec['admins'] !== null) {
        $rec['admins'] = null;
        $changed = true;
    }
    if (($scope === 'all' || $scope === 'user') && isset($rec['users'][$uid])) {
        unset($rec['users'][$uid]);
        $changed = true;
    }
    if ($scope === 'all' && !empty($rec['users'])) {
        $rec['users'] = array();
        $changed = true;
    }
    if (!$changed) {
        return false;
    }
    if ($rec['admins'] === null && empty($rec['users'])) {
        return devdadcl_option_delete('devdadcl_m_' . $hash); // empty rows are removed, not kept as orphans (proved)
    }
    $rec['actor'] = $uid;
    $rec['ts']    = time();
    return devdadcl_option_write('devdadcl_m_' . $hash, $rec, false);
}

/** Is $until currently in force? (0 = forever, future ts = snoozed.) */
function devdadcl_until_active($until, $now = null)
{
    $now = ($now === null) ? time() : (int) $now;
    return $until !== null && ((int) $until === 0 || (int) $until > $now);
}

/** Is this notice hidden for every administrator right now (the all-admins entry alone)? */
function devdadcl_mute_active_for_admins($hash)
{
    $rec = devdadcl_mute_get($hash);
    return $rec !== null && devdadcl_until_active($rec['admins']);
}

/** Is this notice hidden for $uid right now (their own mute OR an all-admins mute)? */
function devdadcl_mute_active_for_user($hash, $uid = null)
{
    $rec = devdadcl_mute_get($hash);
    if ($rec === null) {
        return false;
    }
    if (devdadcl_until_active($rec['admins'])) {
        return true;
    }
    $uid = ($uid === null) ? get_current_user_id() : (int) $uid;
    return isset($rec['users'][$uid]) && devdadcl_until_active($rec['users'][$uid]);
}

/**
 * Compat view used by the capture filter and the summary: hash => effective until for the
 * CURRENT user, containing only mutes that are in force right now.
 */
function devdadcl_muted_map()
{
    $map = array();
    $uid = get_current_user_id();
    foreach (devdadcl_option_keys('devdadcl_m_') as $name) {
        $hash = substr($name, strlen('devdadcl_m_'));
        $rec  = devdadcl_mute_get($hash);
        if ($rec === null) {
            continue;
        }
        if (devdadcl_until_active($rec['admins'])) {
            $map[$hash] = (int) $rec['admins'];
        } elseif (isset($rec['users'][$uid]) && devdadcl_until_active($rec['users'][$uid])) {
            $map[$hash] = (int) $rec['users'][$uid];
        }
    }
    return $map;
}

/* ------------------------------------------------------------------ capture write */

/**
 * Record (or refresh) one captured notice. Touches ONLY this notice's row, and only when
 * something material changed. Returns true when a write happened.
 *
 * A stored mute STAYS when the notice's text or classification changes (owner 2026-09-14); the record is refreshed
 * so the inbox shows the new class (Critical) and the owner restores it from there.
 */
function devdadcl_store_capture($hash, $fingerprint, $node, $class)
{
    $now  = time();
    $crit = ($class['state'] === 'protected') ? 1 : 0;
    $rec  = devdadcl_get_record($hash);

    if ($rec !== null) {
        $changed = false;

        if ((string) (isset($rec['fingerprint']) ? $rec['fingerprint'] : '') !== (string) $fingerprint) {
            $rec['fingerprint'] = (string) $fingerprint;
            $rec['excerpt']     = devdadcl_excerpt($node); // content changed — refresh the safe excerpt
            $changed = true;
        }
        if ((int) $rec['is_critical'] !== $crit) {
            $rec['is_critical'] = $crit;
            $changed = true;
        }
        if ((int) (isset($rec['policy_version']) ? $rec['policy_version'] : 0) !== (int) $class['version']
            || (string) $rec['state'] !== (string) $class['state']) {
            $rec['state']          = $class['state'];
            $rec['confidence']     = $class['confidence'];
            $rec['reason']         = $class['reason'];
            $rec['auto_mutable']   = $class['auto_mutable'] ? 1 : 0;
            $rec['policy_version'] = (int) $class['version'];
            $rec['is_promo']       = ($class['state'] === 'promotional') ? 1 : 0;
            $changed = true;
        }
        if ($now - (int) $rec['last_seen'] > 5 * MINUTE_IN_SECONDS) {
            $rec['last_seen'] = $now;
            $changed = true;
        }
        // Owner 2026-09-14: a hidden notice stays hidden when its text changes (a new version number, a new
        // day count); the classification refresh above still marks it Critical in the inbox.
        if ($changed) {
            return devdadcl_put_record($hash, $rec); // true only when the row holds the change (Codex 2026-09-14)
        }
        return false;
    }

    return devdadcl_put_record($hash, array(
        'hash'             => $hash,
        'fingerprint'      => (string) $fingerprint,
        'identity_version' => DEVDADCL_IDENTITY_VERSION,
        'source'           => devdadcl_detect_source($node),
        'excerpt'          => devdadcl_excerpt($node),
        'first_seen'       => $now,
        'last_seen'        => $now,
        'resolved'         => 0,
        'is_critical'      => $crit,
        'is_promo'         => ($class['state'] === 'promotional') ? 1 : 0,
        'state'            => $class['state'],
        'confidence'       => $class['confidence'],
        'reason'           => $class['reason'],
        'auto_mutable'     => $class['auto_mutable'] ? 1 : 0,
        'policy_version'   => (int) $class['version'],
    )); // proved: the caller may only act (auto-hide, counts) on a record that is really there (Codex 2026-09-14)
}

/* ------------------------------------------------------------------ GC + retention */

/**
 * Garbage-collect: expired snoozes (per-user and all-admins) and orphan mutes (no record).
 * Each cleanup touches only its own row. Returns the number of rows changed/removed.
 */
function devdadcl_gc()
{
    $now = time();
    $n   = 0;
    foreach (devdadcl_option_keys('devdadcl_m_') as $name) {
        $hash = substr($name, strlen('devdadcl_m_'));
        $rec  = devdadcl_mute_get($hash);
        if ($rec === null) {
            continue;
        }
        // Orphan: no inbox record behind the mute. It counted toward muted_count forever. Proved absent, never
        // "absent because the read failed" (DeepSeek round 4: the daily cron runs outside any action window).
        $row = devdadcl_option_row('devdadcl_n_' . $hash);
        if (is_array($row) && !$row[0]) {
            if (devdadcl_option_delete('devdadcl_m_' . $hash)) { // counted only when gone (DESIGN.md 24.5)
                $n++;
            }
            continue;
        }
        $changed = false;
        if ($rec['admins'] !== null && !devdadcl_until_active($rec['admins'], $now)) {
            $rec['admins'] = null;
            $changed = true;
        }
        foreach ($rec['users'] as $uid => $until) {
            if (!devdadcl_until_active($until, $now)) {
                unset($rec['users'][$uid]);
                $changed = true;
            }
        }
        if ($changed) {
            $landed = ($rec['admins'] === null && empty($rec['users']))
                ? devdadcl_option_delete('devdadcl_m_' . $hash)
                : devdadcl_option_write('devdadcl_m_' . $hash, $rec, false);
            if ($landed) {
                $n++;
            }
        }
    }
    return $n;
}

/**
 * Enforce the record cap. Eviction order: resolved first, then oldest-seen — but an
 * UNRESOLVED PROTECTED record is never evicted for being old (the audit reproduced the
 * oldest critical record being trimmed away at 201). If the cap cannot be met without
 * dropping protected records, they stay and a storage warning is flagged for the UI.
 */
function devdadcl_enforce_retention($keep = array())
{
    $inbox = devdadcl_get_inbox();
    if (!empty($GLOBALS['devdadcl_option_keys_failed'])) {
        return 0; // the enumeration failed: neither evict nor clear the warning on a read that answered nothing (DeepSeek round 3)
    }
    $over  = count($inbox) - DEVDADCL_MAX_RECORDS;
    if ($over <= 0) {
        devdadcl_option_write('devdadcl_storage_warning', 0);
        return 0;
    }

    // Evictable: resolved, or not protected. With Auto-hide on, a VISIBLE record is an owner's Restore decision: evicting it
    // would make the notice "first seen" again and hidden again without a click (Codex 2026-09-14), so it stays.
    $auto = function_exists('devdadcl_get_int') && devdadcl_get_int('auto_hide_new', 0);
    $evictable = array();
    $keep = array_flip(array_map('strval', (array) $keep)); // records created in THIS request stay (Codex round 3: a full
    // inbox evicted the notice just captured and hidden, leaving a mute with no Restore target)
    foreach ($inbox as $hash => $rec) {
        if (isset($keep[(string) $hash])) {
            continue;
        }
        if (!empty($rec['resolved']) || (empty($rec['is_critical']) && (!$auto || devdadcl_mute_get($hash) !== null))) {
            $evictable[$hash] = $rec;
        }
    }
    uasort($evictable, function ($a, $b) {
        $ra = empty($a['resolved']) ? 1 : 0;
        $rb = empty($b['resolved']) ? 1 : 0;
        if ($ra !== $rb) {
            return $ra <=> $rb; // resolved rows leave first
        }
        return ((int) $a['last_seen']) <=> ((int) $b['last_seen']); // then oldest
    });

    $removed = 0;
    foreach ($evictable as $hash => $rec) {
        if ($removed >= $over) {
            break;
        }
        // Mute row first, verified; a record is dropped only once its mute is gone (Codex round 4: the other order left a
        // mute with no record and no Restore target).
        $mrow = devdadcl_option_row('devdadcl_m_' . $hash); // proved absent, never "absent because the read failed" (Codex round 7)
        if (false === $mrow || ($mrow[0] && !devdadcl_option_delete('devdadcl_m_' . $hash))) {
            continue; // proved delete only: a mute row that stayed (or could not be read) keeps its record (DeepSeek round 6)
        }
        if (devdadcl_delete_record($hash)) { // counted only when the record is really gone (DESIGN.md 24.5)
            $removed++;
        }
    }
    // Could the cap not be honored without dropping protected records? Keep them + warn.
    devdadcl_option_write('devdadcl_storage_warning', ($removed < $over) ? 1 : 0);
    return $removed;
}

/* ------------------------------------------------------------------ purge + migration */

/** Delete ALL notice data (records, mutes, legacy stores). Distinct from Reset settings. */
function devdadcl_purge_notice_data()
{
    $n = 0;
    $GLOBALS['devdadcl_purge_failed'] = 0; // records + mutes that stayed (Codex round 2: a mute that stayed was "deleted")
    foreach (devdadcl_option_keys('devdadcl_n_') as $name) {
        if (devdadcl_option_delete($name)) { // counted only when gone (DESIGN.md 24.5)
            $n++;
        } else {
            $GLOBALS['devdadcl_purge_failed']++;
        }
    }
    foreach (devdadcl_option_keys('devdadcl_m_') as $name) {
        if (!devdadcl_option_delete($name)) {
            $GLOBALS['devdadcl_purge_failed']++;
        }
    }
    foreach (array('devdadcl_notices', 'devdadcl_muted', 'devdadcl_storage_warning') as $legacy) {
        if (!devdadcl_option_delete($legacy)) {
            $GLOBALS['devdadcl_purge_failed']++; // legacy stores count too (Codex round 5)
        }
    }
    return $n;
}

/**
 * One-time migration from the schema-1 whole-option stores. Old records move to per-record
 * rows; old mutes become ALL-ADMINS mutes (that is what the shared map effectively was) —
 * EXCEPT for protected records, which must not inherit a legacy mute (the legacy mute may
 * be exactly the wrong verdict a policy fix was meant to reverse).
 */
function devdadcl_migrate_legacy()
{
    if ((int) get_option('devdadcl_schema', 0) >= DEVDADCL_SCHEMA_VERSION) {
        return false;
    }

    // Read with proof: a failed SELECT here would look like an empty legacy store, and the marker + deletes below would
    // drop the real one for good (DeepSeek round 4). admin_init runs outside any action window.
    $row_inbox = devdadcl_option_row('devdadcl_notices');
    $row_muted = devdadcl_option_row('devdadcl_muted');
    if (false === $row_inbox || false === $row_muted) {
        return false; // read failed: try again on the next load, nothing dropped
    }
    $old_inbox = $row_inbox[0] ? $row_inbox[1] : array();
    $old_muted = $row_muted[0] ? $row_muted[1] : array();
    $old_inbox = is_array($old_inbox) ? $old_inbox : array();
    $old_muted = is_array($old_muted) ? $old_muted : array();

    $moved = true;
    foreach ($old_inbox as $hash => $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $existing = devdadcl_get_record($hash);
        if ($existing !== null) {
            // Landed on an earlier run: only its mute can still be missing (Codex round 2: skipping the whole
            // row here dropped the mute for good once the legacy stores were deleted).
            if (isset($old_muted[$hash]) && empty($existing['is_critical']) && $existing['state'] !== 'protected'
                && devdadcl_mute_get($hash) === null
                && !devdadcl_mute_set($hash, (int) $old_muted[$hash], 'admins', 0)) {
                $moved = false;
            }
            continue;
        }
        $rec += array(
            'hash' => $hash, 'fingerprint' => '', 'identity_version' => 1, 'resolved' => 0,
            'source' => '', 'excerpt' => '', 'first_seen' => 0, 'last_seen' => 0,
            'is_critical' => 0, 'is_promo' => 0, 'state' => 'unknown', 'confidence' => 0.0,
            'reason' => 'migrated from schema 1', 'auto_mutable' => 0, 'policy_version' => 0,
        );
        // Excerpts stored before redaction existed are re-redacted on the way in.
        $rec['excerpt'] = devdadcl_redact($rec['excerpt']);
        if (!devdadcl_put_record($hash, $rec)) {
            $moved = false; // a record that did not land is retried on the next load; the legacy store stays
            continue;
        }

        if (isset($old_muted[$hash]) && empty($rec['is_critical']) && $rec['state'] !== 'protected') {
            if (!devdadcl_mute_set($hash, (int) $old_muted[$hash], 'admins', 0)) {
                $moved = false; // a mute that did not move keeps the legacy store for the next run (Codex 2026-09-14)
            }
        }
        // Protected records: legacy mute deliberately NOT carried over — fail visible.
    }

    if (!$moved) {
        return false; // nothing dropped, marker not written: the migration runs again next time (DESIGN.md 24.5)
    }
    // The marker FIRST (proved): a legacy store dropped before a lost marker write would re-run the migration
    // over nothing and lose the mutes (DeepSeek 2026-09-14).
    if (!devdadcl_option_write('devdadcl_schema', DEVDADCL_SCHEMA_VERSION)) {
        return false;
    }
    devdadcl_option_delete('devdadcl_notices');
    devdadcl_option_delete('devdadcl_muted');
    return true;
}
