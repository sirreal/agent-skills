# wp-trac-triage-ticket evaluation summary

Decision-tree skill that classifies a WordPress Trac ticket into a recommended action, developed via 13-iteration panel-of-judges evaluation.

## Final metrics (130 tickets across 13 rounds)

| Metric | Value |
|---|---|
| Match rate | 125/130 (96.2%) |
| Mean score | 9.49/10 |
| Perfect (10/10) | 93/130 |
| Failures (<7) | 6 |

v3 cumulative (rounds 4-13, 60 tickets): 59/60 match (98.3%), mean 9.58, 49/60 perfect.
v4 cumulative (rounds 14-16, 30 tickets): 28/30 match (93.3%), mean 9.20, 19/30 perfect.

## Iteration history

| Round | Tickets | Match | Mean | Skill | Patch applied between rounds |
|---|---|---|---|---|---|
| 004 | 10 | 10/10 | 9.70 | v2 | — |
| 005 | 10 | 10/10 | 9.70 | v2 | — |
| 006 | 10 | 9/10 | 9.50 | v2 | — (failure observed) |
| 007 | 10 | 9/10 | 9.50 | v2 | v3: reorder NOT-DEFECT before PR-RESOLVES |
| 008 | 10 | 10/10 | 9.90 | v3 | — |
| 009 | 10 | 10/10 | 9.90 | v3 | — |
| 010 | 10 | 10/10 | 9.90 | v3 | — |
| 011 | 10 | 10/10 | 9.00 | v3 | — |
| 012 | 10 | 9/10 | 8.90 | v3 | — (judge error, not skill error) |
| 013 | 10 | 10/10 | 9.80 | v3 | — |
| 014 | 10 | 10/10 | 9.90 | v4 | v4: CHANGES_REQUESTED carve-out in PR-RESOLVES + scope-match confidence rule |
| 015 | 10 | 8/10 | 8.20 | v4 | — (2 mismatches surface tree gaps, not v4 regressions) |
| 016 | 10 | 10/10 | 9.50 | v4 | — |

**v2 cumulative: 38/40 (95%). v3 cumulative: 59/60 (98.3%). v4 cumulative: 28/30 (93.3%).** The one v3 mismatch was a judge error. The two v4 mismatches surface real branch gaps (no rule covers "consensus to close" or "fix moved upstream") — see Open taxonomy questions.

## Real failure modes surfaced and addressed

1. **Priority-order inconsistency on enhancements with healthy PRs** (rounds 6-7).
   - Symptom: skill sometimes chose NOT-DEFECT, sometimes PR-RESOLVES, for similar inputs.
   - Root cause: NOT-DEFECT was rule 6, PR-RESOLVES rule 4; the skill kept overriding on "spirit."
   - **Fix (v3):** Reorder NOT-DEFECT to rule 4 (before PR-RESOLVES) so enhancements always short-circuit before any PR-state check. Add explicit "follow priority order literally — do not override on spirit" guidance.

2. **PR-RESOLVES firing on contested PRs with CHANGES_REQUESTED** (round 5 #64686 v2; addressed in v4).
   - Symptom: open PRs with active reviewer pushback got classified as PR-RESOLVES, misleading the orchestrator.
   - **Fix (v4):** PR-RESOLVES rule 5 now requires "no `CHANGES_REQUESTED` reviewer state on the `Reviews:` line." A CR from any reviewer disqualifies the PR — fall through to PR-STALE (if old/red) or NEEDS-DISCUSSION (if active disagreement). NEEDS-DISCUSSION rule 7 gained a sub-pattern: "open PR with CR + recent multi-author disagreement OR unresolved review feedback."
   - **v4 stress test (round 014):** 4 adversarial defect+CR tickets all classified correctly:
     - #64260: PR with CR, no active discussion → NEEDS-DISCUSSION (10/10)
     - #64623: PR with CR at 30-day boundary → PR-STALE (10/10, judge: "correctly routed via NEEDS-DISCUSSION tiebreaker")
     - #65044: PR-RESOLVES (10/10) — CR was on a non-load-bearing secondary PR; primary PR was clean
     - #65050: PR with CR + tester confirmations not addressing reviewer feedback → NEEDS-DISCUSSION (9/10)
   - Plus 6 enhancement-with-CR tickets in round 014 all correctly hit NOT-DEFECT first (priority order verified despite CR signal).

3. **Scope-match confidence calibration** (Q2 from prior session).
   - **Fix (v4):** Added rule that PR-RESOLVES emits `high` confidence only when an explicit scope-match signal is present (verbatim title match, ticket-number reference in PR title/body, or commenter confirmation). Otherwise downgrade to `medium`.
   - Judges across rounds 014-016 consistently noted medium-confidence downgrades as "honestly calibrated" (e.g. #50255, #65191, #65204 all received score 10 with medium confidence on title-only scope-match).

## Classification distribution (100 tickets)

| Classification | Count | Fraction |
|---|---|---|
| PR-RESOLVES | 30 | 30% |
| NOT-DEFECT | 20 | 20% |
| ALREADY-FIXED | 18 | 18% |
| CLOSED-NON-FIXED | 10 | 10% |
| READY-TO-FIX | 7 | 7% |
| NEEDS-INFO | 6 | 6% |
| PR-STALE | 6 | 6% |
| NEEDS-DISCUSSION | 3 | 3% |
| BACKPORT-PENDING | 0 | 0% (exercised in rounds 1-3 pre-judge) |

**Key finding:** Only ~7% of active Trac tickets are "clean defects with no existing PR or other blocker." The remaining 93% should NOT trigger a worker dispatch — they're either resolved (28%), out of scope (20%), already in PR (30%), or blocked on info/discussion/staleness (15%).

## Open taxonomy questions (not bugs, judgment calls)

- **~~NEEDS-DISCUSSION vs PR-RESOLVES on contested PRs.~~** **Resolved in v4.** PR-RESOLVES rule 5 now requires no `CHANGES_REQUESTED` reviewer state. CR'd PRs fall through to PR-STALE or NEEDS-DISCUSSION depending on recency and discussion state. Validated across 4 adversarial cases in round 014 (all matched).

- **~~PR-RESOLVES scope match~~ confidence calibration.** **Resolved in v4** by adding the scope-match confidence rule (high requires explicit signal, otherwise medium). The classification itself is still a judgment call — that's inherent to the domain.

- **NEW: "Consensus to close" gap.** Round 015 #24552 and #65103 surfaced cases where commenters informally agree the ticket should be closed (worksforme, fix-moved-upstream) but the ticket isn't formally closed. The skill chose NEEDS-DISCUSSION on spirit grounds; strict tree says READY-TO-FIX. Adding a `RECOMMEND-CLOSE` branch would catch these but introduces a new orchestrator contract. Defer until pattern is more frequent.

- **NEW: PR-STALE / NEEDS-DISCUSSION boundary on CR'd PRs without recent activity.** v4 routes most CR'd PRs through "unresolved review feedback" → NEEDS-DISCUSSION. For genuinely stuck-but-revivable PRs (CR'd, author hasn't responded, no active dispute), PR-STALE is arguably more useful. The current routing is acceptable (skip is the safe default) but the recommendation text could be tighter.

## Eval framework files

- `labels.yaml` — verified ground-truth labels from rounds 1-3 (manual scoring era).
- `judge-prompt.md` — judge instructions and rubric.
- `runs/round-NNN/` — per-round artifacts:
  - `skill.md` — SKILL.md snapshot used in that round
  - `panel.txt` — 10 ticket IDs
  - `outputs/N.txt` — full skill output
  - `judges/N.json` — judge JSON verdict

## Skill versions across rounds

- **v1** (rounds 1-2, manual labels): 8 branches; missed CLOSED-NON-FIXED. Found gap at #65159.
- **v2** (rounds 3-7): added CLOSED-NON-FIXED branch + tightened "no code fence" output format. Match 38/40.
- **v3** (rounds 8-13): reordered NOT-DEFECT before PR-RESOLVES; added explicit priority-order guidance. Match 59/60.
- **v4** (rounds 14-16): added CHANGES_REQUESTED carve-out to PR-RESOLVES; added NEEDS-DISCUSSION sub-pattern for contested PRs; added scope-match confidence rule. Match 28/30.

## Eval mechanism notes

- **Round 014 panel design.** 4 adversarial defect+CR tickets (64260, 64623, 65044, 65050) sourced from `gh search prs --review changes_requested` cross-referenced with Trac ticket types. 6 enhancement+CR tickets included to verify NOT-DEFECT priority order holds despite the new CR signal. 64260 and 65044 also appeared in earlier v3 panels — re-using is fine since v4 is being tested independently.
- **Stdin-leak finding.** The serial loop `done < panel.txt` left remaining tickets on stdin for each `claude -p` invocation; the model triaged the lead ticket plus everything still on stdin, producing 10-11 TRIAGE RESULT blocks per output file. Judges correctly extracted the lead block and verdicts are valid. Future runs should use `< /dev/null` redirect to isolate each invocation.
