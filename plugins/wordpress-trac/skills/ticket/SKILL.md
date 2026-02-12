---
name: ticket
description: Look up a WordPress Trac ticket (#30000 or https://core.trac.wordpress.org/ticket/30000)
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/skills/ticket/scripts/ticket.php:*)
argument-hint: [--discussion] <ticket-number>
---

Look up WordPress Trac ticket information.

If no ticket number was provided, ask the user which ticket they want to look up.

When ready, run:
- Basic info: `${CLAUDE_PLUGIN_ROOT}/skills/ticket/scripts/ticket.php <ticket-number>`
- With comments: `${CLAUDE_PLUGIN_ROOT}/skills/ticket/scripts/ticket.php --discussion <ticket-number>`

Use `--discussion` when the user asks about "discussion", "comments", or "conversation" on a ticket.
