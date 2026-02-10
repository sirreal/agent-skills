# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Claude Code plugin marketplace repository. Contains plugins published under the `sirreal` marketplace namespace.

**Marketplace install:**
```
/plugin marketplace add sirreal/agent-skills
/plugin install wordpress-trac@sirreal
```

## Repository Structure

```
.claude-plugin/
├── marketplace.json    # Marketplace config (namespace, owner, plugin list)
└── plugin.json         # Marketplace-level metadata
plugins/
└── wordpress-trac/     # Individual plugin
    ├── .claude-plugin/plugin.json  # Plugin manifest
    ├── commands/       # Command definitions (YAML frontmatter + instructions)
    └── scripts/        # PHP executables called by commands
```

## Development

**Requirements:** PHP 8.4+ with curl extension (uses native `Dom\HTMLDocument`, no Composer dependencies)

**Test scripts directly:**
```bash
./plugins/wordpress-trac/scripts/ticket.php 62345
./plugins/wordpress-trac/scripts/changeset.php 59734
./plugins/wordpress-trac/scripts/search.php "status=new component=Editor"
```

**Test plugin locally:**
```bash
claude --plugin-dir ./plugins/wordpress-trac
```

## Plugin Patterns

**Commands** use YAML frontmatter with `allowed-tools` restricting Bash to specific scripts via `${CLAUDE_PLUGIN_ROOT}`:
```yaml
---
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/scripts/ticket.php:*)
---
```

**Scripts** fetch from `core.trac.wordpress.org` using:
- TSV API (`?format=tab`) for structured ticket data
- HTML parsing with `Dom\HTMLDocument` for changesets/comments
- Trac wiki → markdown conversion for descriptions
