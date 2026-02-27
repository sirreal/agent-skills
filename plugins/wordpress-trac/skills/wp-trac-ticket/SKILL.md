---
name: wp-trac-ticket
description: >-
  Look up a WordPress Trac ticket by number (#30000), URL
  (https://core.trac.wordpress.org/ticket/30000), or see the comments and discussion
  on a ticket. Use when the user asks about a trac ticket, wants ticket details,
  status, description, or asks to see the comments, discussion, or conversation on a
  ticket.
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php:*)
argument-hint: [--discussion] <ticket-number>
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
