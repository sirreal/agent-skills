---
name: wp-trac-timeline
description: >-
  Browse WordPress Trac timeline activity feed. Use when the user asks about recent
  WordPress core Trac activity, commits, ticket changes, or what a specific author
  has been working on. Matches queries like "recent trac activity", "what has USER
  done on trac", "WordPress core commits this week", "show me USER's trac
  contributions".
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-timeline/scripts/timeline.php:*)
argument-hint: <time period and/or author>
context: fork
---

Query WordPress Trac timeline for: $1

## Script reference

!`${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-timeline/scripts/timeline.php --help`

## Date calculation

Today's date is used to calculate dates. Convert relative dates to explicit parameters:

| User says | Calculation |
|-----------|-------------|
| "last 2 weeks" | `--daysback=14` |
| "past month" | `--daysback=30` |
| "last 7 days" | `--daysback=7` |
| "around Jan 12, 2023" | `--from=2023-01-15 --daysback=7` (center on the date) |
| "January 2023" | `--from=2023-01-31 --daysback=31` |
| "since Feb 1" | Calculate days from Feb 1 to today |

## Examples

| User request | CLI arguments |
|--------------|---------------|
| "my activity last 2 weeks" | `--author=jonsurrell --daysback=14` |
| "what did saxmatt and ryan do in early 2005" | `--from=2005-01-15 --daysback=15 --author=saxmatt --author=ryan` |
| "all trac activity yesterday" | `--daysback=1` |
| "jonsurrell's commits this month" | `--author=jonsurrell --daysback=30` |

## Instructions

1. Parse the user's request to identify time period and author(s)
2. Calculate `--from` and `--daysback` from relative dates
3. Run: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-timeline/scripts/timeline.php"` [arguments]
4. Present the timeline results
