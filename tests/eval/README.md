# wp-trac-ticket CLI eval harness

Autonomous loop to score and improve the `ticket.php` CLI's output against
the rendered Trac ticket page in a browser. Stops at two consecutive 10/10
rounds across a 10-ticket panel, or at iteration 50.

## Files

- `rubric.md` — single 0-10 scoring rubric with Required/Important/Noise/Nice-to-have tiers.
- `panel.json` — the 5 frozen stratification representatives.
- `judge-prompt.md` — template for scoring subagents.
- `patcher-prompt.md` — template for the patcher subagent.
- `pick-fresh.php` — picks N random non-fixed tickets from Trac.
- `round-prepare.php` — runs the CLI for a round's panel and writes outputs.
- `round-aggregate.php` — reads judge JSONs, aggregates, decides if round is 10/10.
- `runs/round-<NNN>/` — per-round artifacts.

## Prerequisites

- `~/.config/wp-trac/cookie` (or `$TRAC_COOKIE_FILE`) with a valid Trac cookie.
- `WP_LOGIN_USER` and `WP_LOGIN_PASS` exported (for Playwright form login).
- Playwright MCP available to the driving Claude session.

## One round

The deterministic parts run from PHP. The judge and patcher dispatches are
Claude Agent calls — the driving session orchestrates them.

1. `tests/eval/round-prepare.php <N>` — picks fresh panel, runs CLI, writes
   `runs/round-<NNN>/cli/<ticket>.md`.
2. Driving session dispatches 10 judge subagents in parallel. Each judge:
   - Receives ticket, paths, rubric.
   - Uses Playwright to log in (once per session) and snapshot the page.
   - Writes `runs/round-<NNN>/judges/<ticket>.json`.
3. `tests/eval/round-aggregate.php <N>` — produces `report.json`, exits 0 if perfect.
4. If perfect: record perfect-round count, advance to next round.
5. If not perfect: driving session dispatches the patcher subagent with the
   gap report. Patcher edits `ticket.php` and/or `html-to-markdown.php`.
   Commit-per-round so any later regression can be bisected.

## Stop conditions

- Two **consecutive** perfect rounds → stop and report converged.
- Round 50 reached → stop and report residual gaps + abstention list.
- CLI crashes during a round → abort, revert the patcher's last commit,
  exit with `patch broke ticket #N` report.
- Login failure → abort the run entirely.

## Failure handling cheatsheet

| Failure | Action |
| --- | --- |
| Login fails | Abort run. |
| Fresh ticket 404 | Redraw (up to 3 attempts), continue with 9 if all fail. |
| CLI exits non-zero on a panel ticket | Abort run + `git revert HEAD --no-edit`. |
| Judge returns malformed JSON | Retry once, then score that ticket 0 with reason. |
| Network flake | Retry once, then score that ticket 0 with reason. |
