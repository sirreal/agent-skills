---
description: Look up a WordPress Trac ticket (#123 or https://core.trac.wordpress.org/ticket/123)
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/scripts/ticket.php:*)
argument-hint: [--discussion] <ticket-number>
---

Fetch and display WordPress Trac ticket information.

- Basic ticket info: `/ticket 12345`
- Ticket discussion/comments: `/ticket --discussion 12345`

When the user asks about "discussion", "comments", or "conversation" on a ticket, use `--discussion`.

```sh
${CLAUDE_PLUGIN_ROOT}/scripts/ticket.php $ARGS
```
