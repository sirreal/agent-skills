---
name: wp-trac-changeset
description: >-
  Look up a WordPress Trac changeset (commit, revision) to see the commit message,
  author, and timestamp. Accepts [41062], r41062, or
  https://core.trac.wordpress.org/changeset/41062. Use when the user asks about a
  commit, revision, changeset, or "what changed in rNNNNN".
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-changeset/scripts/changeset.php:*)
argument-hint: <changeset-number>
---

Fetch and display WordPress Trac changeset information.

If no changeset number was provided, ask the user which changeset they want to look up.

When ready, run:

```sh
!`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-changeset/scripts/changeset.php"` $1
```

Present the changeset information. If the commit message references ticket numbers (e.g., #12345), mention them.
