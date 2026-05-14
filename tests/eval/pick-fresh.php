#!/usr/bin/env php
<?php
/**
 * Pick N random fresh ticket IDs from the recent Trac activity list,
 * excluding any IDs already in the fixed panel.
 *
 * Usage:
 *   pick-fresh.php [--count=5] [--exclude=65171,45354,62345,65167,64977]
 *
 * Prints one ticket ID per line on stdout.
 */

function trac_apply_cookie($ch): void {
    $file = getenv('TRAC_COOKIE_FILE');
    if ($file === false || $file === '') {
        $home = getenv('XDG_CONFIG_HOME') ?: (getenv('HOME') . '/.config');
        $file = $home . '/wp-trac/cookie';
    }
    if (!is_readable($file)) {
        return;
    }
    $cookie = trim(file_get_contents($file));
    if ($cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
}

$count = 5;
$exclude = [];
for ($i = 1; $i < $argc; $i++) {
    if (preg_match('/^--count=(\d+)$/', $argv[$i], $m)) {
        $count = (int)$m[1];
    } elseif (preg_match('/^--exclude=([\d,]+)$/', $argv[$i], $m)) {
        $exclude = array_filter(array_map('intval', explode(',', $m[1])));
    }
}

// Pull up to 500 recently-modified tickets, mix of open and closed.
$url = "https://core.trac.wordpress.org/query?col=id&order=changetime&desc=1&max=500&format=tab";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'wp-trac-eval/1.0');
trac_apply_cookie($ch);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

if ($code < 200 || $code >= 300 || $body === false) {
    fwrite(STDERR, "Error: could not fetch ticket list (HTTP {$code})\n");
    exit(1);
}

$lines = preg_split('/\r?\n/', trim($body));
array_shift($lines); // header row

$ids = [];
foreach ($lines as $line) {
    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
    if (ctype_digit($line)) {
        $n = (int)$line;
        if (!in_array($n, $exclude, true)) {
            $ids[] = $n;
        }
    }
}

if (count($ids) < $count) {
    fwrite(STDERR, "Error: only {$count} tickets available, fewer than requested\n");
    exit(1);
}

shuffle($ids);
$picks = array_slice($ids, 0, $count);
foreach ($picks as $p) {
    echo "{$p}\n";
}
