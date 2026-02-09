# WordPress Trac Plugin

A Claude Code plugin for working with WordPress Trac tickets, changesets, and searches.

## Prerequisites

- PHP 8.4+ (required for `Dom\HTMLDocument`)
- curl extension enabled

## Installation

Add this plugin directory to Claude Code:

```bash
claude --plugin-dir /path/to/wordpress-trac-skills
```

Or add to your settings:

```json
{
  "pluginDirs": ["/path/to/wordpress-trac-skills"]
}
```

## Commands

### `/wp-trac-ticket <ticket-number>`

Look up a WordPress Trac ticket by number or URL.

```
/wp-trac-ticket 12345
/wp-trac-ticket #12345
/wp-trac-ticket https://core.trac.wordpress.org/ticket/12345
```

### `/wp-trac-ticket-discussion <ticket-number>`

Look up comments/discussion on a WordPress Trac ticket.

```
/wp-trac-ticket-discussion 12345
```

### `/wp-trac-changeset <changeset-number>`

Look up a WordPress Trac changeset by number.

```
/wp-trac-changeset 61418
/wp-trac-changeset r61418
/wp-trac-changeset [61418]
```

### `/wp-trac-search <description>`

Search WordPress Trac tickets using natural language or specific filters.

```
/wp-trac-search open HTML API tickets
/wp-trac-search closed REST API bugs
/wp-trac-search tickets about block editor
```

The search command supports various filters:
- `--component` - Filter by component (e.g., "HTML API", "REST API")
- `--status` - Filter by status (new, assigned, accepted, closed, reopened, reviewing)
- `--type` - Filter by type (defect, enhancement, feature request, task)
- `--milestone` - Filter by milestone
- `--summary` / `--description` - Text search

Run the script with `--help` for full documentation:

```bash
./scripts/wp-trac-search.php --help
```
