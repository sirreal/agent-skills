---
name: wp-trac-search
description: >-
  Search and filter WordPress Trac tickets on core.trac.wordpress.org. Use when the
  user wants to find tickets, search for bugs, query open or closed tickets, filter
  by component (e.g., "HTML API", "Editor", "REST API"), or search by status,
  milestone, owner, reporter, type, or keywords. Example queries: "find open HTML API
  tickets", "search for closed editor bugs", "tickets assigned to jonsurrell".
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-search/scripts/search.php:*)
argument-hint: <description of what to search for>
context: fork
---

Search WordPress Trac tickets for: $1

If no search criteria were provided, ask the user what they want to search for.

## Script reference

!`${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-search/scripts/search.php --help`

## Translation guide

When translating natural language to CLI arguments:

- "open tickets" → --status=new --status=assigned --status=accepted --status=reopened --status=reviewing
- "closed tickets" → --status=closed
- Only use --component when there's a very obvious match to an exact component name (e.g., "HTML API", "REST API")
- When in doubt, use --summary and --description text search instead of --component
- Use --summary for text search in ticket title
- Use --description for text search in ticket body

## Examples

| User request | CLI arguments |
|--------------|---------------|
| "open HTML API tickets" | --component="HTML API" --status=new --status=assigned --status=accepted --status=reopened --status=reviewing |
| "closed REST API bugs" | --component="REST API" --status=closed --type="defect (bug)" |
| "tickets about block editor" | --summary="block editor" --description="block editor" |

## Instructions

1. Parse the user's description to identify filters and search terms
2. Build the correct CLI arguments using the documented options
3. Run: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-search/scripts/search.php"` [arguments]
4. Review results — try several different queries to find good results
5. Try different combinations: broader/narrower searches, different text terms, with/without component filters
6. Return the final results
