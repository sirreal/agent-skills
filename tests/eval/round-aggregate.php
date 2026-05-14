#!/usr/bin/env php
<?php
/**
 * Aggregate per-ticket judge JSON outputs for one round.
 *
 * Expects each judge to have written to:
 *   tests/eval/runs/round-<NNN>/judges/<ticket>.json
 *
 * Produces:
 *   tests/eval/runs/round-<NNN>/report.json
 *     {round, panel:[ticket,...], scores:{ticket: score,...},
 *      gap_frequency:{missing_required, missing_important, noise_present},
 *      missing_judges:[ticket,...], perfect_round: bool,
 *      details:[{full judge verdict},...]}
 *
 * Exit code: 0 if every ticket scored 10 (perfect round); 1 otherwise.
 *
 * Usage:
 *   round-aggregate.php <round-number>
 */

if ($argc < 2 || !ctype_digit($argv[1])) {
    fwrite(STDERR, "Usage: round-aggregate.php <round-number>\n");
    exit(1);
}
$round = (int)$argv[1];
$round_dir = sprintf('%s/runs/round-%03d', __DIR__, $round);
if (!is_dir($round_dir)) {
    fwrite(STDERR, "Error: round dir not found: {$round_dir}\n");
    exit(2);
}
$panel = json_decode(file_get_contents("{$round_dir}/panel.json"), true);
if (!is_array($panel) || !isset($panel['all'])) {
    fwrite(STDERR, "Error: invalid round panel.json\n");
    exit(2);
}

$scores = [];
$missing = [];
$gap_freq = [
    'missing_required'  => [],
    'missing_important' => [],
    'noise_present'     => [],
];
foreach ($panel['all'] as $t) {
    $path = "{$round_dir}/judges/{$t}.json";
    if (!is_readable($path)) {
        $missing[] = $t;
        continue;
    }
    $j = json_decode(file_get_contents($path), true);
    // A valid verdict must carry the full schema described in judge-prompt.md.
    // Accepting partial JSON (e.g. just `{"score": 10}`) would let an empty
    // gap-array fall through to the perfect-round path, falsely converging
    // the harness on a verdict the judge never actually produced.
    $valid = is_array($j)
        && isset($j['ticket'], $j['score'])
        && (string)$j['ticket'] === (string)$t
        && is_int($j['score'])
        && $j['score'] >= 0 && $j['score'] <= 10
        && is_array($j['missing_required']  ?? null)
        && is_array($j['missing_important'] ?? null)
        && is_array($j['noise_present']     ?? null);
    if (!$valid) {
        $missing[] = $t;
        continue;
    }
    $scores[] = $j;
    foreach (['missing_required', 'missing_important', 'noise_present'] as $tier) {
        foreach ($j[$tier] as $item) {
            $key = strtolower(trim($item));
            $gap_freq[$tier][$key] = ($gap_freq[$tier][$key] ?? 0) + 1;
        }
    }
}

$ticket_scores = array_column($scores, 'score', 'ticket');
// A round is perfect only when every judge reported AND every judge reported
// no gaps. Don't trust the numeric score alone — a judge that returns
// `score: 10` while still listing items in any gap bucket has produced an
// inconsistent verdict; treat that as not-perfect so the harness keeps
// iterating instead of false-stopping at convergence.
$perfect = ($missing === []) && count($scores) === count($panel['all']);
foreach ($scores as $s) {
    if ((int)$s['score'] !== 10) { $perfect = false; break; }
    foreach (['missing_required', 'missing_important', 'noise_present'] as $tier) {
        if (!empty($s[$tier])) { $perfect = false; break 2; }
    }
}

foreach ($gap_freq as $tier => &$bucket) {
    arsort($bucket);
}
unset($bucket);

$report = [
    'round'         => $round,
    'panel'         => $panel['all'],
    'scores'        => $ticket_scores,
    'gap_frequency' => $gap_freq,
    'missing_judges'=> $missing,
    'perfect_round' => $perfect,
    'details'       => $scores,
];
file_put_contents("{$round_dir}/report.json", json_encode($report, JSON_PRETTY_PRINT) . "\n");

echo "Round {$round} report at {$round_dir}/report.json\n";
echo "Scores: " . json_encode($ticket_scores) . "\n";
echo "Perfect: " . ($perfect ? "yes" : "no") . "\n";
if ($missing !== []) {
    echo "Missing judges: " . implode(', ', $missing) . "\n";
}
exit($perfect ? 0 : 1);
