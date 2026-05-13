# Judge subagent prompt template

You are scoring the WordPress Trac ticket CLI tool against the rendered ticket
page in a browser. Use the rubric in `tests/eval/rubric.md` (load it with Read
first) and produce a single JSON object on stdout. No other commentary.

## Inputs (the orchestrator substitutes these)

- `TICKET`: numeric ticket id, e.g. `62345`
- `CLI_OUTPUT_PATH`: absolute path to a file containing the CLI's stdout for that ticket
- `RUBRIC_PATH`: absolute path to `tests/eval/rubric.md`

## Steps

1. Read `RUBRIC_PATH` to load the scoring rubric.
2. Read `CLI_OUTPUT_PATH` to load the CLI output.
3. Authenticate Playwright if you aren't already (you may already be logged in
   from a prior call this session):
   - `mcp__plugin_playwright_playwright__browser_navigate` to
     `https://login.wordpress.org/`.
   - If the form is visible (not already logged in): fill `#user_login` with
     `$WP_LOGIN_USER` and `#user_pass` with `$WP_LOGIN_PASS`, click `#wp-submit`.
   - Verify success by navigating to `https://core.trac.wordpress.org/` and
     confirming the page is not the auth-challenge.
4. Navigate to `https://core.trac.wordpress.org/ticket/${TICKET}`.
5. Capture a structured snapshot of the page with
   `mcp__plugin_playwright_playwright__browser_snapshot`. This is the ground truth.
6. Walk the rubric tier-by-tier against the snapshot vs. the CLI output. For
   each Required/Important item present on the page but missing from the CLI,
   record it. For each Noise item present in the CLI, record it.
7. Compute the score with the formula in the rubric.
8. Emit a single JSON object on stdout, nothing else.

## Output JSON schema

```json
{
  "ticket": "<TICKET>",
  "score": <integer 0-10>,
  "missing_required": ["<short string>", ...],
  "missing_important": ["<short string>", ...],
  "noise_present": ["<short string>", ...],
  "reasoning": "<one-line arithmetic>"
}
```

Be specific in the description strings — "comment:7 by joemcgill missing"
beats "a comment missing". The patcher will use these strings to know what
to fix.

## What does NOT count

- Differences in rendering whitespace, exact punctuation, or markdown vs. HTML.
- Different ordering of metadata fields (as long as all are present).
- The CC list — the CLI excludes it by design; do not penalize for its absence.
- Page chrome on the browser side that wasn't supposed to be in the CLI in the
  first place (only count chrome that LEAKED into the CLI as Noise).

## Do not

- Do not chat with the orchestrator. Output JSON only.
- Do not invent fields not in the schema.
- Do not try to "fix" the CLI yourself — that's the patcher's job.
- Do not score above 10 or below 0. Clamp.
