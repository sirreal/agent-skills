---
name: wp-trac-ticket
description: >-
  Look up a specific WordPress Trac ticket by its number to see details (status,
  component, type, milestone, description) or discussion/comments. Accepts #62345,
  62345, or https://core.trac.wordpress.org/ticket/62345. Use whenever the user
  references a specific ticket number or wants to read a particular ticket's
  comments/discussion. Do NOT use wp-trac-search for single ticket lookups.
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php:*)
argument-hint: "[--discussion | --prs] <ticket-number>"
---

Look up WordPress Trac ticket information.

If no ticket number was provided, ask the user which ticket they want to look up.

## Input formats

Pass the numeric ticket number to the script (e.g. `30000`).

## Choosing a mode

Three modes are available. Pick one based on what the user wants.

| User says | Mode |
|-----------|------|
| "look up ticket 30000" | basic (no flag) |
| "what's the status of ticket 12345" | basic (no flag) |
| "what are people saying on ticket 30000" | `--discussion` |
| "show me the comments on #62345" | `--discussion` |
| "summarize the discussion on ticket 50000" | `--discussion` |
| "show me the PRs for ticket 64776" | `--prs` |
| "what pull requests are open for #62345" | `--prs` |
| "any code submitted for ticket 50000?" | `--prs` |

Modes are exclusive. If both `--discussion` and `--prs` are passed, the last one on the command line wins.

## Commands

- Basic info: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` <ticket-number>
- With comments: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` --discussion <ticket-number>
- With PRs: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` --prs <ticket-number>

## Output

Basic mode returns: id, component, summary, type, status, milestone, and description.

Discussion mode returns: a list of comments with author and content for each.

PRs mode returns: a list of associated GitHub pull requests sorted with open first then closed, each by most-recent update. For each PR: number, title, state, author, URL, created/updated/closed dates, additions/deletions, whether it touches tests, CI status (when present), and review status.
