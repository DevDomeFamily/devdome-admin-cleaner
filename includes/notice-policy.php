<?php
/**
 * Notice classification policy — the single decision about what may be hidden automatically.
 *
 * THE RULE THIS FILE EXISTS TO ENFORCE: a notice may only be muted automatically when it is
 * proven marketing. Everything else stays on screen. Not "probably fine to hide", not
 * "matched no keyword" — proven.
 *
 * That asymmetry is deliberate, because the two failure modes are not comparable. Leaving a
 * promo visible costs the administrator a second of annoyance. Hiding a "your backup failed"
 * or "a vulnerability was reported in one of your plugins" notice costs them the one warning
 * they were going to get, silently, forever.
 *
 * The previous implementation classified any `update-nag` as promotional and non-critical,
 * so a one-click cleanup stored a permanent mute for the WordPress core update notice. That
 * is the bug this policy is built around.
 *
 * Design notes:
 *
 *   - The result is a versioned RECORD, not a boolean: state, confidence, reason,
 *     auto_mutable. A stored mute keeps the version it was made under, so a later policy
 *     change can invalidate decisions made by an older, weaker classifier.
 *
 *   - Keywords are never the safety boundary on their own. "Security Pro, 30% off" is
 *     marketing; "a security vulnerability was detected" is not. The classifier looks for
 *     OPERATIONAL signals (structure, core notice classes, update state, known subsystems)
 *     and treats their presence as protection regardless of what marketing words sit
 *     alongside them.
 *
 *   - Mixed signals are protected. A notice that says both "backup failed" and "upgrade to
 *     Pro" is an operational notice with an ad stapled to it.
 *
 *   - Unknown, empty, malformed, low-confidence: protected by default. Failing visible is
 *     the only safe direction.
 */

defined('ABSPATH') || exit;

/** Bump when the rules change materially; stored decisions record the version they used. */
const DEVDADCL_POLICY_VERSION = 3;

/** A promo must clear this to be auto-muted. */
const DEVDADCL_AUTOMUTE_CONFIDENCE = 0.8; // two corroborating marketing phrases; one stock phrase ('maybe later') is not enough to mute by itself (Codex round 2)

/** Bytes of a single notice we are willing to inspect. Beyond this: not classified. */
const DEVDADCL_MAX_NODE_BYTES = 262144;

/** Bytes of captured admin output we are willing to parse. Beyond this: passed through. */
const DEVDADCL_MAX_BUFFER_BYTES = 2097152;

/**
 * Signals that a notice is OPERATIONAL — it reports the state of the site. Any hit makes
 * the notice protected, whatever else it contains.
 *
 * Multilingual by construction where it matters: the structural signals (classes, wrapper
 * ids, core markers) do not translate, which is why they carry the weight rather than the
 * English phrases. The phrase list is a secondary net for notices with no useful markup.
 */
function devdadcl_operational_signals()
{
    return array(
        // Structural — these do not vary by locale, which is the point.
        'class' => array(
            'update-nag',            // core "please update" — NEVER promotional
            'update-message',
            'updating-message',
            'wc-connect',
            'woocommerce-message',
            'wp-site-health',
            'site-health',
            'recovery-mode',
        ),
        // Wrapper ids core and major subsystems use for operational notices.
        'id' => array(
            'update-nag',
            'wp_verify_email_notice',
            'php-upgrade-notice',
            'health-check',
            'site-health',
            'recovery-mode-notice',
        ),
        // Phrases that describe SITE STATE. Deliberately conservative: each one is a thing
        // that happened to the site, not a thing being sold.
        'phrase' => array(
            // Generic failure wording (round 2: "Payment gateway cannot process orders. Maybe later" and "Error: forms
            // are not sending. Upgrade to Pro" resolved to promotional). Operational always wins over marketing.
            'error:',                  'an error',             'errors occurred',
            'failed',                  'could not',            'cannot ',
            'unable to',               'not working',          'not sending',
            'not configured',          'misconfigured',        'not writable',
            'permission denied',       'stopped working',
            'is available',            'please update',        'update now',
            'new version',             'automated update',     'automatic update',
            'auto-update',             'updates are disabled', 'recovery mode',
            'database needs',          'database update',      'database upgrade',
            'update database',         'needs to be upgraded', 'requires an upgrade',
            'outdated version of php', 'no longer supported',  'unsupported version',
            'site health',             'critical issues',      'recommended improvements',
            'backup failed',           'backup is',            'restore did not',
            'no recoverable backup',   'scheduled backup',     'scheduled task',
            'wp-cron',                 'failed to run',        'failed to complete',
            'vulnerability',           'vulnerable',           'malware',
            'was detected',            'security issue',       'security alert',
            'has expired',             'licence has expired',  'license has expired',
            'support are disabled',    'store running',        'keep the store',
            // Localized operational copy — the structural signals cover most locales, but
            // these are the highest-traffic phrases where markup alone is thin.
            'ist verfügbar',           'bitte jetzt',          'aktualisieren',
            'vulnerabilidad',          'seguridad',            'está disponible',
            'est disponible',          'mise à jour',          'sicherheit',
        ),
    );
}

/** Marketing signals. Only ever used to promote a notice to 'promotional' — never to demote one. */
function devdadcl_promo_phrases()
{
    return array(
        'go pro', 'get pro', 'upgrade to pro', 'pro version', 'premium version',
        'only available in the premium', 'unlock', 'buy now', 'discount', '% off',
        'black friday', 'cyber monday', 'sale', 'coupon', 'free trial', 'start your free',
        'leave a review', 'review us', 'rate us', 'rate this plugin', 'enjoying the plugin',
        'special offer', 'limited time', 'save 20', 'save 30', 'add-on',
        // Rating begs (owner 2026-09-15: a Media Sweep "leave me a ★★★★★ rating" notice classified as
        // unknown, so the score ignored it). ' rating' keeps its leading space so "migrating" never matches.
        ' rating', 'rate plugin', 'rate the plugin', 'write a review', 'leave us a review',
        'five star', '5 star', '5-star', '★', 'maybe later', 'i already did', 'already rated',
        'support continued development', 'donate', 'enjoying ', 'love this plugin', 'like this plugin',
    );
}

/**
 * Classify one notice node.
 *
 * @param string $node Raw notice HTML, exactly as captured.
 * @return array{state:string, confidence:float, reason:string, auto_mutable:bool, version:int}
 */
function devdadcl_classify($node)
{
    $node = (string) $node;

    // Oversized input is not inspected at all. Refusing to classify is safe; spending
    // unbounded time on a regex over attacker-influenced markup is not.
    if (strlen($node) > DEVDADCL_MAX_NODE_BYTES) {
        return devdadcl_result('unknown', 0.0, 'node exceeds the inspection size limit', false);
    }

    $text = strtolower(wp_strip_all_tags($node));
    $text = trim(preg_replace('/\s+/', ' ', $text));

    // Nothing to judge. An empty or markup-only node is not a notice we understand.
    if ($text === '') {
        return devdadcl_result('malformed', 0.0, 'no readable text in the node', false);
    }

    // Balance check: an unclosed wrapper means the capture is unreliable, so any conclusion
    // drawn from it is unreliable too.
    if (substr_count(strtolower($node), '<div') !== substr_count(strtolower($node), '</div>')) {
        return devdadcl_result('malformed', 0.0, 'unbalanced markup — the node may be truncated', false);
    }

    $signals = devdadcl_operational_signals();
    $lower_markup = strtolower($node);

    // --- OPERATIONAL SIGNALS. Any hit ends the decision: protected. -------------------
    $attrs = devdadcl_attr_values($node, 'class') . ' ' . devdadcl_attr_values($node, 'id');
    $attrs = strtolower($attrs);
    foreach ($signals['class'] as $cls) {
        if (strpos($attrs, $cls) !== false) {
            return devdadcl_result('protected', 1.0, 'operational notice class: ' . $cls, false);
        }
    }
    foreach ($signals['id'] as $id) {
        if (strpos($attrs, $id) !== false) {
            return devdadcl_result('protected', 1.0, 'operational notice id: ' . $id, false);
        }
    }
    // notice-error is core's "something is wrong" class. It is never marketing.
    if (preg_match('/\bnotice-error\b|\bnotice-alt\s+notice-error\b/i', $lower_markup)) {
        return devdadcl_result('protected', 1.0, 'core error notice class', false);
    }
    foreach ($signals['phrase'] as $phrase) {
        if (strpos($text, $phrase) !== false) {
            return devdadcl_result('protected', 0.95, 'operational phrase: "' . $phrase . '"', false);
        }
    }

    // --- MARKETING. Only reached when NO operational signal fired, which is what makes
    //     "Security Pro, 30% off" resolve to promotional while "a security vulnerability
    //     was detected" resolves to protected.
    $hits = array();
    foreach (devdadcl_promo_phrases() as $phrase) {
        if (strpos($text, $phrase) !== false) {
            $hits[] = $phrase;
        }
    }
    if ($hits) {
        // Confidence rises with corroboration: one stock phrase is weaker evidence than
        // several. Only the high-confidence band may be muted automatically.
        $confidence = round(min(1.0, 0.7 + (0.1 * (count($hits) - 1))), 2); // rounded: 0.7 + 0.1 is 0.79999 in floating point
        return devdadcl_result(
            'promotional',
            $confidence,
            'marketing phrases: ' . implode(', ', array_slice($hits, 0, 3)),
            $confidence >= DEVDADCL_AUTOMUTE_CONFIDENCE
        );
    }

    // --- NEITHER. This is the case the old code got wrong by defaulting to "hideable".
    return devdadcl_result('unknown', 0.0, 'no operational or marketing signal recognised', false);
}

/** Build a classification record. auto_mutable is forced false unless the state allows it. */
function devdadcl_result($state, $confidence, $reason, $auto_mutable)
{
    return array(
        'state'        => $state,
        'confidence'   => (float) $confidence,
        'reason'       => (string) $reason,
        // Belt and braces: only a promotional state can EVER be auto-mutable, whatever a
        // caller passes. A future edit that gets the flag wrong still cannot hide a warning.
        'auto_mutable' => ($state === 'promotional') && (bool) $auto_mutable
                          && $confidence >= DEVDADCL_AUTOMUTE_CONFIDENCE,
        'version'      => DEVDADCL_POLICY_VERSION,
    );
}

/** Collected values of one attribute across the node (class="a" class='b' class=c). */
function devdadcl_attr_values($node, $attr)
{
    $out = '';
    if (preg_match_all('/(?<![\w-])' . preg_quote($attr, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $node, $m, PREG_SET_ORDER)) { // data-class= is not class= (DeepSeek round 8)
        foreach ($m as $set) {
            $out .= ' ' . (isset($set[2]) && $set[2] !== '' ? $set[2]
                        : (isset($set[3]) && $set[3] !== '' ? $set[3]
                        : (isset($set[4]) ? $set[4] : '')));
        }
    }
    return $out;
}

/**
 * THE single gate every automatic path must call — one-click cleanup, every preset,
 * Developer Mode, scheduled tidying. There is deliberately no other way to authorise an
 * automatic mute, because the audit found the bypass path is how protected notices get
 * hidden: one entry point classifies carefully and another does not.
 */
function devdadcl_may_automute($node)
{
    $c = devdadcl_classify($node);
    return !empty($c['auto_mutable']);
}
