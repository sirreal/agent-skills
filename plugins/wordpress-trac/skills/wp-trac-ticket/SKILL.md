---
name: wp-trac-ticket
description: >-
  Look up a specific WordPress Trac ticket by its number to see all
  information about it: metadata, description, attachments, linked
  changesets, discussion comments, and GitHub pull requests. Accepts
  #62345, 62345, or https://core.trac.wordpress.org/ticket/62345. Use
  whenever the user references a specific ticket number. Do NOT use
  wp-trac-search for single ticket lookups.
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php:*)
argument-hint: "[--short] <ticket-number>"
---

Look up WordPress Trac ticket information.

If no ticket number was provided, ask the user which ticket they want to look up.

## Input formats

Pass the numeric ticket number to the script (e.g. `30000`). The script
also accepts `#30000` or a full ticket URL.

## Choosing a mode

The default mode returns everything: metadata, description, attachments,
changesets, comments, and pull requests. Use `--short` only when the user
wants a quick metadata-only glance (no comments/attachments/changesets/PRs).

| User says | Mode |
|-----------|------|
| "look up ticket 30000" | default |
| "show me ticket #62345" | default |
| "what's the discussion on ticket 50000" | default |
| "what PRs are open for #62345" | default |
| "quick status check on ticket 12345" | `--short` |
| "just the metadata for #30000" | `--short` |

## Commands

- Full: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` <ticket-number>
- Short: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` --short <ticket-number>

## Output

Both modes start with a metadata header (component, summary, reporter, owner,
type, status, resolution, priority, severity, version, milestone, keywords,
focuses; empty fields are suppressed) followed by the description.

The default mode then appends, in order:
- **Attachments**: each attached file with uploader, date, and a direct URL.
- **Changesets**: each Trac changeset that referenced the ticket via an
  `In [N]:` auto-comment, with committer, date, URL, and commit message.
- **Discussion**: each comment with author, comment number, date, any
  inline field-change context (status / owner / milestone / resolution
  changes — keyword churn is intentionally dropped), and body.
- **Pull Requests**: GitHub PRs linked to the ticket, sorted open-first then
  by most-recent update. Each shows number, title, state, author, URL,
  created/updated/closed dates, +/− line counts, whether tests are touched,
  CI status, and reviewer status.

## On auth failure

If the script reports that auth is required (HTTP 403 / "run /wp-trac-auth"),
invoke the `wp-trac-auth` skill to (re)authenticate, then re-run this command
with the same arguments.
