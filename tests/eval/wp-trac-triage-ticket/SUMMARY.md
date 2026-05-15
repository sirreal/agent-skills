# wp-trac-triage-ticket evaluation summary

Decision-tree skill that classifies a WordPress Trac ticket into a recommended action, developed via 10-iteration panel-of-judges evaluation.

## Final metrics (100 tickets across 10 rounds)

| Metric | Value |
|---|---|
| Match rate | 97/100 (97%) |
| Mean score | 9.58/10 |
| Perfect (10/10) | 74/100 |
| Failures (<7) | 4 |

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

**v2 cumulative: 38/40 (95%). v3 cumulative: 59/60 (98.3%).** The one v3 mismatch was a judge error (priority direction inverted), not a skill error.

## Real failure modes surfaced and addressed

1. **Priority-order inconsistency on enhancements with healthy PRs** (rounds 6-7).
   - Symptom: skill sometimes chose NOT-DEFECT, sometimes PR-RESOLVES, for similar inputs.
   - Root cause: NOT-DEFECT was rule 6, PR-RESOLVES rule 4; the skill kept overriding on "spirit."
   - **Fix (v3):** Reorder NOT-DEFECT to rule 4 (before PR-RESOLVES) so enhancements always short-circuit before any PR-state check. Add explicit "follow priority order literally — do not override on spirit" guidance.

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

- **NEEDS-DISCUSSION vs PR-RESOLVES** on contested PRs. Round 5 ticket #64686 had an open PR with CHANGES_REQUESTED and four-way disagreement; skill picked NEEDS-DISCUSSION (overriding priority order), judge accepted. v3's "follow priority literally" rule would force PR-RESOLVES instead, which might be misleading. If this case recurs, consider adding "PR-RESOLVES requires no active CHANGES_REQUESTED with multi-author disagreement" — but that's judgment leakage into the rule.

- **PR-RESOLVES scope match** is a judgment call. A PR can match part of a ticket but not its full scope, or vice versa. The current rule trusts the model's judgment; this generates most of the 9-scoring (not perfect-10) decisions.

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
