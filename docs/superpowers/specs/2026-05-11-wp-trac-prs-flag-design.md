# wp-trac-ticket `--prs` flag design

Date: 2026-05-11

## Goal

Add a `--prs` flag to the existing `wp-trac-ticket` skill that lists GitHub pull requests associated with a WordPress Trac ticket, using the `api.wordpress.org` PR endpoint. Output is compact markdown intended for quick triage of what code work exists against a ticket.

## Non-goals

- No new skill, no new directory, no new script file.
- No fetching of PR-side data from GitHub (the wordpress.org endpoint already aggregates what we need).
- No body/description rendering for PRs; users follow the URL for full discussion.
- No combination with `--discussion` in a single run.

## Architecture

Single-file change to `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php` adding a third exclusive mode parallel to basic and `--discussion`. `SKILL.md` is updated to document the flag.

The script flow becomes:

1. Parse args. Track a single `$mode` variable, initialised to `'basic'`. Encountering `--discussion` sets `$mode = 'discussion'`. Encountering `--prs` sets `$mode = 'prs'`. The ticket-number positional is parsed as before.
2. Dispatch on `$mode`: `prs` → PR mode and exit; `discussion` → discussion mode (unchanged); `basic` → basic mode (unchanged).
3. Flag combination is genuine last-wins because each flag overwrites `$mode`. `--discussion --prs` → PR mode; `--prs --discussion` → discussion mode. No error path is introduced.

This is a small departure from the current arg-parse loop, which uses an independent `$discussion_mode` boolean. The new structure preserves backward compatibility (a lone `--discussion` still selects discussion mode) while making the precedence rule explicit.

## Endpoint

```
https://api.wordpress.org/dotorg/trac/pr/?trac=core&ticket=<NUMBER>
```

Returns a JSON array of PR objects. Empty array means no PRs.

The same `trac_apply_cookie` helper is applied to the curl handle for consistency with the other modes, even though `api.wordpress.org` is unlikely to need it. This keeps a single curl-setup pattern across modes.

HTTP error handling matches the other modes: non-2xx prints to stderr and exits 1. JSON decode failure also exits 1 with a stderr message.

## PR object fields used

From the JSON response:

- `repo`, `number`, `html_url` — identity / link
- `state` — `open` | `closed`
- `title` — heading
- `user.name` — author
- `created_at`, `updated_at`, `closed_at` — dates (rendered as `YYYY-MM-DD`)
- `changes.additions`, `changes.deletions` — size
- `touches_tests` — boolean
- `check_runs` — object: `{ "Run name": "status", ... }`
- `reviews` — object: `{ "STATE": ["user1", "user2"], ... }`

Other fields in the response (e.g. `mergeable_state`, `body`, `changes.patch_url`) are ignored for this mode.

## Sort order

Open PRs first, closed PRs after. Within each group, sort by `updated_at` descending (most-recently-updated first). Rationale: open PRs are the most actionable; recent activity beats stale activity inside each group.

## Output format

Top-level heading identifies the ticket:

```
# Trac Ticket #<NUMBER> Pull Requests
```

For an empty array:

```
_No pull requests found._
```

For each PR:

```
## #<number> — <title>

- **State:** <state>
- **Author:** <user.name>
- **URL:** <html_url>
- **Created:** <YYYY-MM-DD> · **Updated:** <YYYY-MM-DD>
- **Closed:** <YYYY-MM-DD>          (only when state is closed and closed_at is present)
- **Changes:** +<additions> / -<deletions> · **Touches tests:** <yes|no>
- **CI:** <Run>: <status>, <Run>: <status>     (omitted entirely if check_runs is empty)
- **Reviews:** <STATE>: user1, user2; <STATE>: user3   (literal "_none_" if empty)
```

Notes:

- Title is the literal `title` field; no markdown escaping. Trac PR titles are conventionally plain text.
- Dates are formatted as `YYYY-MM-DD` (date portion of the ISO 8601 timestamp).
- `touches_tests`: `true` → `yes`, `false` → `no`.
- `check_runs` line is omitted when the object is empty/absent, since "no CI" is uninformative noise.
- `reviews` line is always present so the reader can quickly see review status. `_none_` italicized when there are no reviews.

## SKILL.md changes

- `argument-hint`: change to `"[--discussion | --prs] <ticket-number>"`.
- Decision table: add rows for PR-related phrasings, e.g.:
  - "show me the PRs for ticket 64776" → `--prs`
  - "what pull requests are open for #62345" → `--prs`
  - "any code submitted for ticket 50000?" → `--prs`
- Commands section: add a third bullet for `--prs <ticket-number>`.
- Output section: add a paragraph describing what `--prs` returns.

No change to `allowed-tools` is required — the existing `Bash(${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php:*)` glob already permits new arguments.

## Error and edge cases

- Ticket number validation: unchanged — `ctype_digit` check applies before any mode runs.
- HTTP 404 from the PR endpoint: treated as a generic non-2xx error, exits 1. (The endpoint behaviour for unknown tickets is not documented to return an empty array vs. 404; either is handled.)
- Malformed JSON: stderr message, exit 1.
- A PR object missing an expected key: render the line with a placeholder dash (`—`) rather than crashing. This is defensive against the API evolving without a contract bump.

## Testing

Manual verification, since the project has no automated tests for these scripts:

1. `./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --prs 64776` — ticket known to have PRs.
2. A ticket with no PRs — confirms empty-array path.
3. A non-existent ticket number — confirms error path.
4. `./… --prs --discussion 64776` and `./… --discussion --prs 64776` — confirms last-wins.
5. `./… 64776` (no flag) — confirms basic mode unchanged.
6. `./… --discussion 64776` — confirms discussion mode unchanged.

## Out of scope (explicit YAGNI)

- A `--prs-full` flag to include PR bodies. The body field is verbose and the GitHub URL is already in the output.
- Filtering by state via flag (e.g. `--prs --open`). Open PRs already sort first; users can scan.
- Caching responses. Each invocation hits the API; volume is low.
- Combining `--discussion` with `--prs` to render both in one run. Two invocations are explicit and cheap.
