---
name: wp-trac-changeset
description: Look up a WordPress Trac changeset ([41062], r41062, or https://core.trac.wordpress.org/changeset/41062)
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-changeset/scripts/changeset.php:*)
argument-hint: <changeset-number>
---

Fetch and display WordPress Trac changeset $1 using:

```sh
${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-changeset/scripts/changeset.php $1
```
