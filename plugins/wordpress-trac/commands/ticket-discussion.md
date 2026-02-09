---
description: Look up WordPress Trac ticket discussion (#123 or https://core.trac.wordpress.org/ticket/123)
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/scripts/ticket-discussion.php:*)
argument-hint: <ticket-number>
---

Fetch and display WordPress Trac ticket discussion #$1 using:

```sh
${CLAUDE_PLUGIN_ROOT}/scripts/ticket-discussion.php $1
```
