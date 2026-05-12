#!/usr/bin/env php
<?php
/**
 * Integration tests against live core.trac.wordpress.org.
 *
 * Skips with exit 0 if cookie/network unavailable so this is safe
 * to call from CI that may not have Trac credentials.
 */

$script = realpath(__DIR__ . '/../../plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php');

$cookie = getenv('TRAC_COOKIE_FILE') ?: (getenv('HOME') . '/.config/wp-trac/cookie');
if (!is_readable($cookie)) {
    fwrite(STDOUT, "SKIP: no Trac cookie at {$cookie} — integration tests skipped\n");
    exit(0);
}

$failures = 0;
$tests = 0;

/**
 * Run ticket.php with the given argv; return [stdout-bytes, stdout, stderr, exit-code].
 * Uses proc_open with array argv so there is no shell layer.
 */
function run(array $args): array {
    global $script;
    $argv = array_merge([$script], $args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($argv, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return [0, '', '', -1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return [strlen($stdout), $stdout, $stderr, $exit];
}

// I1: content-volume sanity for #50040
$tests++;
[$len, $out, , ] = run(['--discussion', '50040']);
if ($len >= 6000) {
    fwrite(STDOUT, "PASS  I1 #50040 discussion length {$len} >= 6000\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  I1 #50040 discussion length {$len} < 6000\n");
}

// I3: code fences in #50040 are balanced (>= 2 markers, even count)
$tests++;
$fences = substr_count($out, "\n```");
if ($fences >= 2 && $fences % 2 === 0) {
    fwrite(STDOUT, "PASS  I3 #50040 has {$fences} balanced code-fence markers\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  I3 #50040 fence count {$fences} (need even and >= 2)\n");
}

// I2 lite: #50040 comment 3 should contain PHP keywords from the embedded plugin code
$tests++;
if (preg_match('/\b(function|define|class)\b/', $out)) {
    fwrite(STDOUT, "PASS  I2 #50040 output contains PHP keywords from embedded code\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  I2 #50040 output missing PHP keywords — <pre> walker may have regressed\n");
}

// I4: control tickets — each must produce non-trivial output.
foreach ([29420, 50040] as $ticket) {
    $tests++;
    [$len, , , ] = run(['--discussion', (string)$ticket]);
    if ($len >= 500) {
        fwrite(STDOUT, "PASS  I4 #{$ticket} produces {$len} bytes (>=500)\n");
    } else {
        $failures++;
        fwrite(STDOUT, "FAIL  I4 #{$ticket} produced only {$len} bytes\n");
    }
}

// N2: missing-cookie path. Temporarily rename the real cookie so the script
// runs unauthenticated, then assert clear failure on STDERR with exit 1.
$tests++;
$cookieBak = $cookie . '.bak-' . getmypid();
if (rename($cookie, $cookieBak)) {
    // Belt-and-suspenders: ensure we restore the cookie even on fatal error.
    register_shutdown_function(function () use ($cookieBak, $cookie) {
        if (file_exists($cookieBak) && !file_exists($cookie)) {
            rename($cookieBak, $cookie);
        }
    });
    try {
        [, , $stderr, $exit] = run(['--discussion', '50040']);
    } finally {
        rename($cookieBak, $cookie);
    }
    // Trac may respond with either HTTP 403 (caught by HTTP-code check) or
    // an HTML auth-challenge page (caught by the <channel> guard). Both
    // count as loud, since either way: exit != 0 and a clear STDERR message.
    $clear = stripos($stderr, 'auth') !== false
        || stripos($stderr, 'not rss') !== false
        || stripos($stderr, 'HTTP') !== false;
    if ($exit !== 0 && $clear) {
        fwrite(STDOUT, "PASS  N2 missing cookie produces clear error\n");
    } else {
        $failures++;
        fwrite(STDOUT, "FAIL  N2 missing cookie did not produce clear error (exit={$exit}, stderr=" . trim($stderr) . ")\n");
    }
} else {
    fwrite(STDOUT, "SKIP  N2 could not rename cookie file\n");
}

fwrite(STDOUT, "\n{$tests} tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
