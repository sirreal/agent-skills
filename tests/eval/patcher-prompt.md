# Patcher subagent prompt template

You receive an aggregated gap report from N judge subagents (one per panel
ticket). Your job is to edit the CLI to address the most common gaps. You may
only modify two files. You may abstain on gaps that have no clean fix.

## Inputs (the orchestrator substitutes these)

- `GAP_REPORT_PATH`: absolute path to a JSON file aggregating this round's
  per-ticket judge outputs.
- `TICKET_PHP_PATH`: absolute path to
  `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php`.
- `HTML_TO_MD_PATH`: absolute path to
  `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/html-to-markdown.php`.
- `RUBRIC_PATH`: absolute path to `tests/eval/rubric.md`.

## Allowed file edits

ONLY these two files. Do not touch anything else.

- `TICKET_PHP_PATH`
- `HTML_TO_MD_PATH`

Tests, SKILL.md, README, and rubric are out of scope for this loop. If a gap
requires changing one of those, abstain with a reason.

## Steps

1. Read `RUBRIC_PATH`, `GAP_REPORT_PATH`, `TICKET_PHP_PATH`, `HTML_TO_MD_PATH`.
2. Aggregate gaps by frequency across the panel. Tackle the most common ones first.
3. For each gap you take on, decide:
   - **Fix locally**: edit `ticket.php` and/or `html-to-markdown.php` to address it.
   - **Abstain**: record `{gap, reason}` with a one-sentence reason. Reasons
     include: would require a new HTTP endpoint, would require touching tests
     or docs, would only be addressable by changing the rubric.
4. After editing, verify the script still parses and runs on at least one
   panel ticket (run `php -l` for syntax, then run `ticket.php <one panel
   number> > /dev/null` for runtime).
5. Emit a single JSON object on stdout describing what you did.

## Output JSON schema

```json
{
  "fixed": [
    {"gap": "<short string>", "approach": "<one-line summary>"}
  ],
  "abstained": [
    {"gap": "<short string>", "reason": "<one sentence>"}
  ],
  "syntax_check": "ok",
  "runtime_check": "ok on ticket <N>"
}
```

## Discipline

- Do not produce a patch each round just to satisfy "make progress." If the
  cleanest move is to abstain on everything (e.g. all gaps are rubric-debate
  items), do so — the orchestrator will detect zero-progress rounds and stop.
- Do not chase a single judge's idiosyncratic complaint if no other judges in
  this round reported the same gap. Look for cross-ticket frequency.
- Do not regress other gaps. Re-read the file before each edit. If you change
  comment-rendering logic for changeset extraction, double-check that field-change
  promote-and-filter still works.
- The Noise tier is real. If your fix for a Required gap adds a Noise item (e.g.
  you start rendering CC), the next round will penalize. Better to abstain than
  to trade one tier for another.
- Avoid adding compatibility shims, dead options, or "fallback" branches for
  hypothetical states. Keep the script tight.

## Do not

- Do not modify any file other than `ticket.php` and `html-to-markdown.php`.
- Do not chat or summarize. Output JSON only.
- Do not invent gap categories not in the input.
