---
description: Look up a WordPress Trac ticket (#123 or https://core.trac.wordpress.org/ticket/123)
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/scripts/wp-trac-ticket.php:*)
argument-hint: <ticket-number>
---

Fetch and display WordPress Trac ticket #$1 using:

```sh
${CLAUDE_PLUGIN_ROOT}/scripts/wp-trac-ticket.php $1
```
