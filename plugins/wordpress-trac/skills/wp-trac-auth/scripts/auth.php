#!/usr/bin/env php
<?php
/**
 * Manage WordPress Trac authentication (the session cookie).
 *
 * Usage:
 *   auth.php status        Report cookie presence and, if present, whether it
 *                          still authenticates (live probe against Trac).
 *   auth.php save          Read a raw Cookie: header from STDIN, validate it,
 *                          write it to the canonical location with restrictive
 *                          permissions, then confirm it authenticates.
 *
 * Exit codes:
 *   0  authenticated (status: valid / save: saved and valid)
 *   1  usage / internal error
 *   2  save: pasted value failed validation (nothing written)
 *   3  status: no cookie present (missing or empty) — anonymous
 *   4  status: cookie present but expired / not authenticated
 *   5  save: cookie written but probe still shows unauthenticated
 *
 * The cookie value is never echoed back. Only derived facts are reported.
 */

require_once __DIR__ . '/lib/trac-auth.php';

/**
 * Live probe: does the saved cookie authenticate against Trac?
 *
 * Uses the same surface and signal as the real scripts — a ticket RSS feed
 * that returns a parseable <channel> only when authenticated/unfiltered.
 * Hits core.trac.wordpress.org only.
 */
function trac_probe(): bool {
    $ch = curl_init('https://core.trac.wordpress.org/ticket/30000?format=rss');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'wp-trac-auth/1.0');
    trac_apply_cookie($ch);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        return false;
    }
    $xml = @simplexml_load_string($body);
    return trac_rss_is_authenticated($xml);
}

$cmd = $argv[1] ?? null;

if ($cmd === 'status') {
    $path = trac_cookie_path();

    if (!is_readable($path)) {
        fwrite(STDERR, "Cookie: missing (no readable file at {$path})\n");
        exit(3);
    }
    $cookie = trim(file_get_contents($path));
    if ($cookie === '') {
        fwrite(STDERR, "Cookie: empty ({$path})\n");
        exit(3);
    }

    $has_auth = str_contains($cookie, 'trac_auth');
    fwrite(STDOUT, "Cookie: present ({$path})\n");
    fwrite(STDOUT, '  length: ' . strlen($cookie) . " bytes\n");
    fwrite(STDOUT, '  contains trac_auth: ' . ($has_auth ? 'yes' : 'no') . "\n");

    if (trac_probe()) {
        fwrite(STDOUT, "  status: valid (authenticated)\n");
        exit(0);
    }
    fwrite(STDOUT, "  status: expired (not authenticated) — run /wp-trac-auth to refresh\n");
    exit(4);
}

if ($cmd === 'save') {
    // Read the cookie from STDIN only, never argv — keeps the session token
    // out of the process list and shell history.
    $input = stream_get_contents(STDIN);
    $cookie = trim($input);

    if ($cookie === '') {
        fwrite(STDERR, "Error: no cookie provided on STDIN. Nothing written.\n");
        exit(2);
    }
    if (!str_contains($cookie, 'trac_auth')) {
        fwrite(STDERR, "Error: that does not look like a Trac cookie (missing trac_auth). "
            . "Copy the full Cookie: request header from a logged-in core.trac.wordpress.org "
            . "request. Nothing written.\n");
        exit(2);
    }

    $path = trac_cookie_path();
    $dir  = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, "Error: could not create directory {$dir}\n");
        exit(1);
    }
    @chmod($dir, 0700);

    if (file_put_contents($path, $cookie . "\n") === false) {
        fwrite(STDERR, "Error: could not write {$path}\n");
        exit(1);
    }
    chmod($path, 0600);
    fwrite(STDOUT, "Saved cookie to {$path} (perms 0600).\n");

    if (trac_probe()) {
        fwrite(STDOUT, "Verified: authenticated.\n");
        exit(0);
    }
    fwrite(STDOUT, "Warning: cookie saved but Trac still treats the request as "
        . "unauthenticated. It may be expired — log in again and re-copy the "
        . "Cookie: header.\n");
    exit(5);
}

fwrite(STDERR, "Usage: auth.php {status|save}\n");
exit(1);
