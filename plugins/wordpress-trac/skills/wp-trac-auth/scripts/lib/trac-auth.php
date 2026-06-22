<?php
/**
 * Shared WordPress Trac authentication helpers.
 *
 * Single source of truth for cookie-file resolution, cookie application,
 * and the auth-required failure signal. Included by every Trac script so
 * they all honor the same resolution order and surface the same cue.
 *
 * The cookie holds the user's Trac session token (trac_auth). It is
 * host-scoped to core.trac.wordpress.org by convention: CURLOPT_COOKIE is
 * NOT host-scoped by curl, so only call trac_apply_cookie() on handles that
 * target Trac. Never apply it to other origins (e.g. api.wordpress.org).
 */

/**
 * Resolve the cookie file path.
 *
 * Order: $TRAC_COOKIE_FILE (if set and non-empty) overrides everything;
 * otherwise $XDG_CONFIG_HOME/wp-trac/cookie (if XDG_CONFIG_HOME set);
 * otherwise ~/.config/wp-trac/cookie.
 */
function trac_cookie_path(): string {
    $file = getenv('TRAC_COOKIE_FILE');
    if ($file === false || $file === '') {
        $home = getenv('XDG_CONFIG_HOME') ?: (getenv('HOME') . '/.config');
        $file = $home . '/wp-trac/cookie';
    }
    return $file;
}

/**
 * Apply the saved Trac cookie to a curl handle, if present.
 *
 * Silent no-op when the file is missing/unreadable/empty, so callers fall
 * back to anonymous requests. Only call this on Trac-bound handles.
 */
function trac_apply_cookie($ch): void {
    $file = trac_cookie_path();
    if (!is_readable($file)) {
        return;
    }
    $cookie = trim(file_get_contents($file));
    if ($cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
}

/**
 * Canonical auth-required hint. Emitted by every script when a Trac request
 * comes back filtered/unauthenticated, so the agent has one reliable cue.
 */
function trac_auth_required_message(): string {
    return 'likely auth required (no valid cookie at $TRAC_COOKIE_FILE, '
        . '$XDG_CONFIG_HOME/wp-trac/cookie, or ~/.config/wp-trac/cookie) — '
        . 'run /wp-trac-auth to (re)authenticate';
}

/**
 * Whether a parsed Trac RSS response looks authenticated.
 *
 * When Trac filters an unauthenticated request it serves an HTML login page
 * instead of RSS, so the body fails to parse as XML or lacks a <channel>.
 * Pass the result of simplexml_load_string(); false means parse failed.
 */
function trac_rss_is_authenticated($xml): bool {
    return $xml !== false && isset($xml->channel);
}
