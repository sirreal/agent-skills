---
description: Look up a WordPress Trac changeset ([123], r123, or https://core.trac.wordpress.org/changeset/123)
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/scripts/changeset.php:*)
argument-hint: <changeset-number>
---

Fetch and display WordPress Trac changeset $1 using:

```sh
${CLAUDE_PLUGIN_ROOT}/scripts/changeset.php $1
```
