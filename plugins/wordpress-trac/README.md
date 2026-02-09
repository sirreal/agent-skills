# WordPress Trac Plugin

A Claude Code plugin for working with WordPress Trac tickets, changesets, and searches.

## Prerequisites

- PHP 8.4+ (required for `Dom\HTMLDocument`)
- curl extension enabled

## Commands

### `/wordpress-trac:ticket <ticket-number>`

Look up a WordPress Trac ticket by number or URL.

```
/wordpress-trac:ticket 12345
/wordpress-trac:ticket #12345
/wordpress-trac:ticket https://core.trac.wordpress.org/ticket/12345
```

### `/wordpress-trac:ticket-discussion <ticket-number>`

Look up comments/discussion on a WordPress Trac ticket.

```
/wordpress-trac:ticket-discussion 12345
```

### `/wordpress-trac:changeset <changeset-number>`

Look up a WordPress Trac changeset by number.

```
/wordpress-trac:changeset 61418
/wordpress-trac:changeset r61418
/wordpress-trac:changeset [61418]
```

### `/wordpress-trac:search <description>`

Search WordPress Trac tickets using natural language or specific filters.

```
/wordpress-trac:search open HTML API tickets
/wordpress-trac:search closed REST API bugs
/wordpress-trac:search tickets about block editor
```

The search command supports various filters:
- `--component` - Filter by component (e.g., "HTML API", "REST API")
- `--status` - Filter by status (new, assigned, accepted, closed, reopened, reviewing)
- `--type` - Filter by type (defect, enhancement, feature request, task)
- `--milestone` - Filter by milestone
- `--summary` / `--description` - Text search

Run the script with `--help` for full documentation:

```bash
./scripts/search.php --help
```
