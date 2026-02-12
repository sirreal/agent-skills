# WordPress Trac Plugin

A Claude Code plugin for working with WordPress Trac tickets, changesets, and searches.

## Installation

```sh
claude plugin marketplace add sirreal/agent-skills
claude plugin install wordpress-trac@sirreal
```

## Prerequisites

- PHP 8.4+ (required for `Dom\HTMLDocument`)
- curl extension enabled

## Demo

```
Tell me about trac ticket 30,000
```

> Ticket #30000 is a joke/commemorative ticket from the WordPress 4.1 milestone era. It references the "Alot" meme from Hyperbole and a Half (a comic about the common misspelling of "a lot") with an image attachment.
>
> The ticket is closed and was filed under the WordPress.org Site component. It's essentially a playful marker celebrating the project reaching 30,000 tickets, similar to how projects sometimes note round-number milestones. The reference to #30005 and Mike Nolan suggests there was some coordination around these milestone ticket numbers.

## Skills

### `/wp-trac-ticket <ticket-number>`

Look up a WordPress Trac ticket by number or URL.

```
/wp-trac-ticket 30000
/wp-trac-ticket #30000
/wp-trac-ticket https://core.trac.wordpress.org/ticket/30000
```

The ticket command supports `--discussion` to include comments:

```
/wp-trac-ticket --discussion 30000
```

### `/wp-trac-changeset <changeset-number>`

Look up a WordPress Trac changeset by number.

```
/wp-trac-changeset 41062
/wp-trac-changeset r27195
/wp-trac-changeset [26851]
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
./scripts/search.php --help
```

### `/wp-trac-timeline <time period and/or author>`

Browse WordPress Trac timeline activity. Useful for seeing what you or others have worked on.

```
/wp-trac-timeline my activity last 2 weeks
/wp-trac-timeline what did saxmatt work on in January 2005
/wp-trac-timeline all trac activity yesterday
```

The timeline command supports:
- `--from=YYYY-MM-DD` - End date (default: today)
- `--daysback=N` - Days to look back (1-90, default: 30)
- `--author=USER` - Filter by author (repeat for multiple)

Run the script with `--help` for full documentation:

```bash
./scripts/timeline.php --help
```
