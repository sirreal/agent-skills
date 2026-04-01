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
argument-hint: "[--discussion] <ticket-number>"
---

Look up WordPress Trac ticket information.

If no ticket number was provided, ask the user which ticket they want to look up.

## Input formats

Pass the numeric ticket number to the script (e.g. `30000`).

## When to use --discussion

| User says | Use --discussion? |
|-----------|-------------------|
| "look up ticket 30000" | No |
| "what's the status of ticket 12345" | No |
| "what are people saying on ticket 30000" | Yes |
| "show me the comments on #62345" | Yes |
| "summarize the discussion on ticket 50000" | Yes |

## Commands

- Basic info: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` <ticket-number>
- With comments: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` --discussion <ticket-number>

## Output

Basic mode returns: id, component, summary, type, status, milestone, and description.

Discussion mode returns: a list of comments with author and content for each.
