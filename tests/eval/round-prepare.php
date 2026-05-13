#!/usr/bin/env php
<?php
/**
 * Prepare one evaluation round:
 *   1. Load fixed panel from panel.json.
 *   2. Pick fresh tickets (excluding fixed set).
 *   3. Run ticket.php on all 10 panel tickets, saving outputs to the round dir.
 *   4. Write panel.json describing this round's panel.
 *
 * Usage:
 *   round-prepare.php <round-number>
 *
 * Round outputs land in:
 *   tests/eval/runs/round-<NNN>/
 *     panel.json
 *     cli/<ticket>.md
 *     judges/  (judge subagents write here later)
 */

function run_proc(array $argv): array {
    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($argv, $desc, $pipes);
    if (!is_resource($proc)) return [-1, '', ''];
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $stdout, $stderr];
}

$root = realpath(__DIR__ . '/../..');
$ticket_script = "{$root}/plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php";
$panel_path    = __DIR__ . '/panel.json';
$pick_script   = __DIR__ . '/pick-fresh.php';

if ($argc < 2 || !ctype_digit($argv[1])) {
    fwrite(STDERR, "Usage: round-prepare.php <round-number>\n");
    exit(1);
}
$round = (int)$argv[1];
$round_dir = sprintf('%s/runs/round-%03d', __DIR__, $round);

if (is_dir($round_dir)) {
    fwrite(STDERR, "Error: round dir already exists: {$round_dir}\n");
    exit(1);
}
mkdir($round_dir . '/cli', 0755, true);
mkdir($round_dir . '/judges', 0755, true);

$panel = json_decode(file_get_contents($panel_path), true);
if (!is_array($panel) || !isset($panel['fixed_panel'])) {
    fwrite(STDERR, "Error: invalid panel.json\n");
    exit(1);
}

$fixed = array_column($panel['fixed_panel'], 'ticket');
$exclude = implode(',', $fixed);
$fresh_count = (int)($panel['fresh_panel_size'] ?? 5);

[$rc, $fresh_raw, $err] = run_proc([$pick_script, "--count={$fresh_count}", "--exclude={$exclude}"]);
if ($rc !== 0 || trim($fresh_raw) === '') {
    fwrite(STDERR, "Error: pick-fresh.php failed (rc={$rc}): {$err}\n");
    exit(1);
}
$fresh = array_values(array_filter(array_map('intval', preg_split('/\r?\n/', trim($fresh_raw)))));
if (count($fresh) !== $fresh_count) {
    fwrite(STDERR, "Error: expected {$fresh_count} fresh tickets, got " . count($fresh) . "\n");
    exit(1);
}

$panel_round = [
    'round'   => $round,
    'fixed'   => $fixed,
    'fresh'   => $fresh,
    'all'     => array_merge($fixed, $fresh),
];
file_put_contents("{$round_dir}/panel.json", json_encode($panel_round, JSON_PRETTY_PRINT) . "\n");

foreach ($panel_round['all'] as $t) {
    $out_path = "{$round_dir}/cli/{$t}.md";
    [$rc2, $out2, $err2] = run_proc([$ticket_script, (string)$t]);
    if ($rc2 !== 0) {
        fwrite(STDERR, "Error: ticket.php #{$t} exited {$rc2}: {$err2}\n");
        exit(2);
    }
    file_put_contents($out_path, $out2);
}

echo "Round {$round} prepared at {$round_dir}\n";
echo "Panel: " . implode(', ', $panel_round['all']) . "\n";
