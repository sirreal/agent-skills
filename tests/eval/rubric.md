# wp-trac-ticket CLI Rubric

Compares the CLI output of `ticket.php <N>` against the rendered Trac ticket
page in a browser. Produces a single integer score in `[0, 10]`.

## Scoring formula

```
score = max(0, 10 - 2*|missing_required| - |missing_important| - |noise_present|)
```

A ticket scores **10** iff there are no items in any of `missing_required`,
`missing_important`, or `noise_present`.

## Tiers

### Required (missing each = −2)

A piece of information that is shown on the ticket page and that a developer
or triager would care about. Each one missing from the CLI output is a
−2 deduction.

- Each non-empty TSV metadata field visible on the page (id, summary,
  reporter, owner, type, status, resolution, priority, severity, version,
  milestone, component, keywords, focuses). CC is explicitly excluded —
  not counted as missing.
- The ticket description body.
- Each comment that has prose content (skip pure keyword-bot churn —
  see Noise tier).
- Each attachment listed on the page (filename + uploader at minimum).
- Each changeset referenced as a "In [N]:" auto-comment on the page.
- Each pull request linked to the ticket.
- The ticket's resolution (for closed tickets).

### Important (missing each = −1)

Context that materially aids comprehension but isn't itself the data.

- Field-change context inline with the comment that carried it
  (e.g. "status changed from assigned to closed" associated with comment:9).
  **Keyword-field changes are excluded by design** — do not flag missing
  "keywords changed: has-patch added" entries.
- Comment numbers (`comment:N`) on each comment. **Changeset auto-comments
  are excluded** — they are surfaced in their own Changesets section keyed
  by `[N]`, and the redundant `comment:N` is intentionally dropped.
- Dates on comments / attachments / changesets.
- Author attribution on each item (comment author, attachment uploader,
  changeset committer).
- The link/URL for each attachment, changeset, and PR.

### Noise (present each = −1)

Items that should NOT appear in CLI output. Each occurrence is a −1.

- Empty/null fields rendered as `**Field:** ` with no value.
- Page-chrome leakage from any HTML scrape: navigation, breadcrumbs,
  "Modify Ticket" form remnants, sidebar links, footer.
- Duplicate content (description echoed in a summary line, the same URL
  rendered twice in adjacent metadata fields).
- Slackbot mention comments ("This ticket was mentioned in Slack...").
- Prbot-authored scaffolding comments — only the pure-scaffolding form
  ("This ticket was mentioned in PR #N…") counts as noise. The CLI
  intentionally re-attributes prbot-FORWARDED GitHub comments
  ("@user commented on PR #N: …prose") to the real GitHub user and
  strips the forwarding preamble; those comments are NOT noise.
- Raw Trac wiki syntax that did not convert to markdown: `{{{`, `}}}`,
  `[[BR]]`, `''italic''`, `=== heading ===`, `[wiki:Foo]`.
- Long URLs duplicated in a comment body when already present in a
  metadata field.
- Pure keyword-bot field-change items rendered as their own entry
  (e.g. an entry that shows only "keywords added: has-patch" with no
  associated prose).
- Editorial chatter from the script itself ("_Fetched at..._",
  "_Generated on..._").
- CC list appearing in the output (CC is excluded by design).

### Nice-to-have (no penalty either direction)

These don't move the score. Listed only so the judge doesn't accidentally
penalize either way.

- Per-field-change individual edit timestamps (the page shows them; the
  CLI doesn't need to surface them).
- The reporter's full name vs. login (either is fine).
- Exact whitespace and line-break preservation.
- A PR's GitHub `mergeable_state` (e.g. the "Merge conflicts" badge on the
  Trac page) is **distinct** from its GitHub Actions CI `check_runs` status.
  The CLI surfaces CI status; a CI-success PR with merge conflicts is not
  a wrong CI report.

## Judge output format

The judge subagent MUST return a single JSON object on stdout with this
schema and no additional commentary:

```json
{
  "ticket": "<ticket-number>",
  "score": <integer 0-10>,
  "missing_required": ["<short description>", ...],
  "missing_important": ["<short description>", ...],
  "noise_present": ["<short description>", ...],
  "reasoning": "<one-line score-arithmetic check>"
}
```

The `reasoning` field shows the score arithmetic so a human auditor can
verify it, e.g. `"2 required missing × -2 = -4; 1 noise × -1 = -1;
score = max(0, 10 - 5) = 5"`.

## Calibration anchors

- A perfectly-rendered output for a ticket with active discussion,
  attachments, and changesets scores 10.
- An output that omits a single comment scores 8 (−2).
- An output that includes the CC list and otherwise has no issues scores 9 (−1).
- An output that omits the resolution field on a closed ticket scores 8 (−2).
- An output with raw `{{{` wiki syntax in three places scores 7 (−3).
