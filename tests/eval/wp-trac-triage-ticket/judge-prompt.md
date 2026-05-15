# Judge prompt — wp-trac-triage-ticket

You are an independent judge evaluating whether the `wp-trac-triage-ticket` skill produced an APPROPRIATE classification for a WordPress Trac ticket. You do not see the conversation that produced the output — judge purely from the ticket data and the skill's decision tree.

## Inputs

You will be told:
- The ticket number `N`.
- The full skill output (everything the skill printed, including the `=== TRIAGE RESULT ===` block).
- Paths to:
  - The skill's decision tree: `/Users/jonsurrell/jon/agent-skills/plugins/wordpress-trac/skills/wp-trac-triage-ticket/SKILL.md`
  - The ticket fetcher: `/Users/jonsurrell/jon/agent-skills/plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php`

## Your procedure

1. Read the skill's decision tree (`SKILL.md`). Internalize the 9 branches and their priority order.
2. Fetch the ticket: `<ticket.php> N`. Read the full output.
3. Walk the decision tree against the ticket data. Determine which branch SHOULD have fired.
4. Compare to what the skill emitted.
5. Score per the rubric below.

## Rubric

- **10** — Classification is correct (matches the branch that should have fired per the decision tree's priority order). Evidence is accurate and load-bearing. Confidence is honestly assessed. Recommendation aligns with classification.
- **8–9** — Classification correct. Minor issues: weak/redundant evidence, slightly mis-calibrated confidence, or recommendation could be tighter. Nothing wrong, just not crisp.
- **5–7** — Classification debatable. Either: (a) the ticket sits at a genuine branch boundary the tree handles ambiguously; or (b) the tree's letter says X but the spirit suggests Y. Defensible but suboptimal.
- **2–4** — Classification is wrong (different branch should have fired), but the output is self-aware (lowered confidence, explicit gap-flag in `notes`).
- **0–1** — Silently wrong: misclassified with high confidence, fabricated evidence, or hallucinated facts not in the ticket.

## Output

Output ONLY a single JSON object on the FINAL line of your response. No prose after. Use this shape exactly:

```json
{"ticket":<N>,"skill_classification":"<X>","judge_classification":"<X>","match":true|false,"score":<0-10>,"issues":["<short strings, may be empty>"],"reasoning":"<one to three sentences>"}
```

Fields:
- `ticket` — integer.
- `skill_classification` — exact classification token the skill emitted.
- `judge_classification` — the branch you concluded should have fired.
- `match` — true iff `skill_classification == judge_classification`.
- `score` — integer 0–10 per rubric.
- `issues` — array of short tags. Examples: `wrong-branch`, `weak-evidence`, `overconfident`, `recommendation-mismatch`, `code-fence-wrapped`, `hallucinated-fact`. Empty array if none.
- `reasoning` — one to three sentences. Cite the load-bearing fact from the ticket.

Do NOT include code fences around the JSON. Plain output only.
