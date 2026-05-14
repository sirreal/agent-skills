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
 *
 * If $envOverride is provided, those keys are merged onto the inherited env.
 */
function run(array $args, ?array $envOverride = null): array {
    global $script;
    $argv = array_merge([$script], $args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = $envOverride === null ? null : array_merge(getenv(), $envOverride);
    $proc = proc_open($argv, $descriptors, $pipes, null, $env);
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

// I1: default mode for #50040 is non-trivial and contains all expected sections.
$tests++;
[$len, $out, $stderr1, $exit1] = run(['50040']);
if ($exit1 !== 0 && stripos($stderr1, 'Could not fetch') !== false) {
    fwrite(STDOUT, "SKIP: Trac unreachable — " . trim($stderr1) . "\n");
    exit(0);
}
if ($exit1 !== 0) {
    $failures++;
    fwrite(STDOUT, "FAIL  I1 #50040 exited {$exit1} — stderr: " . trim($stderr1) . "\n");
} elseif ($len >= 6000) {
    fwrite(STDOUT, "PASS  I1 #50040 default output {$len} >= 6000 bytes\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  I1 #50040 default output {$len} < 6000 bytes\n");
}

// I2: PHP keywords from <pre> blocks survive the markdown conversion.
$tests++;
if (preg_match('/\b(function|define|class)\b/', $out)) {
    fwrite(STDOUT, "PASS  I2 #50040 output contains PHP keywords from embedded code\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  I2 #50040 output missing PHP keywords — <pre> walker may have regressed\n");
}

// I3: code fences are balanced (>= 2 markers, even count).
$tests++;
$fences = substr_count($out, "\n```");
if ($fences >= 2 && $fences % 2 === 0) {
    fwrite(STDOUT, "PASS  I3 #50040 has {$fences} balanced code-fence markers\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  I3 #50040 fence count {$fences} (need even and >= 2)\n");
}

// I4: default output contains the section markers we expect.
$tests++;
$expected_sections = ['## Description', '## Discussion', '## Pull Requests'];
$missing = [];
foreach ($expected_sections as $s) {
    if (strpos($out, $s) === false) $missing[] = $s;
}
if ($missing === []) {
    fwrite(STDOUT, "PASS  I4 #50040 default output has all expected sections\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  I4 #50040 default output missing: " . implode(', ', $missing) . "\n");
}

// I5: extended TSV fields are surfaced (reporter, priority).
$tests++;
if (strpos($out, '**Reporter:**') !== false && strpos($out, '**Priority:**') !== false) {
    fwrite(STDOUT, "PASS  I5 #50040 surfaces extended TSV fields\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  I5 #50040 missing reporter/priority in metadata header\n");
}

// I6: --short omits Discussion and Pull Requests but keeps metadata + description.
$tests++;
[, $short_out, $short_err, $short_exit] = run(['--short', '50040']);
if ($short_exit !== 0) {
    $failures++;
    fwrite(STDOUT, "FAIL  I6 --short exited {$short_exit} — stderr: " . trim($short_err) . "\n");
} else {
    $has_desc        = strpos($short_out, '## Description') !== false;
    $has_discussion  = strpos($short_out, '## Discussion') !== false;
    $has_prs         = strpos($short_out, '## Pull Requests') !== false;
    if ($has_desc && !$has_discussion && !$has_prs) {
        fwrite(STDOUT, "PASS  I6 --short emits description, no discussion/PRs\n");
    } else {
        $failures++;
        fwrite(STDOUT, "FAIL  I6 --short emits unexpected sections (desc={$has_desc} discussion={$has_discussion} prs={$has_prs})\n");
    }
}

// I7: control tickets — each must produce non-trivial default output.
foreach ([29420, 50040] as $ticket) {
    $tests++;
    [$len_c, , $err_c, $exit_c] = run([(string)$ticket]);
    if ($exit_c !== 0) {
        $failures++;
        fwrite(STDOUT, "FAIL  I7 #{$ticket} exited {$exit_c} — stderr: " . trim($err_c) . "\n");
    } elseif ($len_c >= 500) {
        fwrite(STDOUT, "PASS  I7 #{$ticket} produces {$len_c} bytes (>=500)\n");
    } else {
        $failures++;
        fwrite(STDOUT, "FAIL  I7 #{$ticket} produced only {$len_c} bytes\n");
    }
}

// N2: missing-cookie path. Point TRAC_COOKIE_FILE at an empty temp file in
// the child process so the script runs unauthenticated; the real cookie on
// disk is never touched.
$tests++;
$emptyCookie = tempnam(sys_get_temp_dir(), 'wp-trac-empty-');
try {
    [, , $stderr, $exit] = run(
        ['50040'],
        ['TRAC_COOKIE_FILE' => $emptyCookie]
    );
} finally {
    @unlink($emptyCookie);
}
$clear = stripos($stderr, 'auth') !== false
    || stripos($stderr, 'not rss') !== false
    || stripos($stderr, 'HTTP') !== false;
if ($exit !== 0 && $clear) {
    fwrite(STDOUT, "PASS  N2 missing cookie produces clear error\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  N2 missing cookie did not produce clear error (exit={$exit}, stderr=" . trim($stderr) . ")\n");
}

fwrite(STDOUT, "\n{$tests} tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
