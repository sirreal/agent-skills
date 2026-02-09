# CLAUDE.md

## Project Overview

This is a **Claude Code plugin marketplace repository** containing WordPress Trac integration tools. The repository hosts plugins distributed via the Claude Code plugin marketplace, enabling AI assistants to query WordPress core development tickets, changesets, and search results directly.

**Author:** Jon Surrell (`sirreal`)

## Repository Structure

```
.
├── CLAUDE.md                        # This file
├── README.md                        # Installation instructions and plugin listing
├── .claude-plugin/
│   ├── plugin.json                  # Plugin metadata (name, version, author, license)
│   └── marketplace.json             # Marketplace registry (owner, plugin list)
└── plugins/
    └── wordpress-trac/              # WordPress Trac integration plugin
        ├── README.md                # Plugin documentation and usage examples
        ├── commands/                # Claude Code command definitions (YAML front-matter + Markdown)
        │   ├── ticket.md
        │   ├── ticket-discussion.md
        │   ├── changeset.md
        │   └── search.md
        └── scripts/                 # Executable PHP scripts (entry points)
            ├── ticket.php           # Fetch ticket metadata and description
            ├── ticket-discussion.php # Fetch ticket comments/discussion
            ├── changeset.php        # Fetch changeset info
            └── search.php           # Search tickets with filters
```

## Tech Stack

- **Language:** PHP 8.4+ (uses `Dom\HTMLDocument` which is a PHP 8.4 feature)
- **Dependencies:** `curl` PHP extension (no package manager)
- **Build system:** None — scripts are directly executable via shebang (`#!/usr/bin/env php`)
- **Testing:** No test suite or CI/CD pipeline
- **Package manager:** None (no Composer, no npm)

## Architecture

### Plugin Structure

Each plugin follows this pattern:

1. **Command definitions** (`commands/*.md`) — YAML front-matter declares the command metadata:
   - `description`: What the command does
   - `allowed-tools`: Which Bash tool patterns can execute (references scripts via `${CLAUDE_PLUGIN_ROOT}`)
   - `argument-hint`: Placeholder shown to users
   - `context`: Optional execution context (e.g., `fork` for search)

2. **Script implementations** (`scripts/*.php`) — Executable PHP scripts that:
   - Accept CLI arguments
   - Fetch data from `https://core.trac.wordpress.org/` via curl
   - Parse responses (TSV or HTML)
   - Output formatted Markdown to stdout
   - Write errors to stderr with non-zero exit codes

### Data Flow

- **TSV-based endpoints** (`ticket.php`, `search.php`): Append `?format=tab` to Trac URLs, parse tab-separated values with `fgetcsv()`, stream to temp files for memory efficiency
- **HTML-based endpoints** (`changeset.php`, `ticket-discussion.php`): Fetch full HTML pages, parse with `Dom\HTMLDocument`, convert to Markdown via recursive `convertHTML()` function

### Common Code Patterns

- Input validation: `ctype_digit()` for numeric checks, URL parsing for ticket URLs
- BOM stripping from TSV headers: `preg_replace('/^\xEF\xBB\xBF/', '', ...)`
- curl usage: `CURLOPT_FOLLOWLOCATION` enabled, custom User-Agent strings (`wp-trac-*/1.0`)
- HTTP status validation: Check response codes in 2xx range
- Trac wiki syntax conversion: `{{{` / `}}}` to Markdown fenced code blocks
- HTML-to-Markdown conversion: Recursive `convertHTML()` handles links, formatting, lists, code blocks, blockquotes
- Relative URLs made absolute against `https://core.trac.wordpress.org`

## Adding a New Plugin

1. Create a new directory under `plugins/<plugin-name>/`
2. Add `commands/` directory with `.md` command definitions
3. Add `scripts/` directory with executable scripts (include shebang, `chmod +x`)
4. Register the plugin in `.claude-plugin/marketplace.json` under the `plugins` array
5. Update `README.md` with the new plugin listing

## Adding a New Command to wordpress-trac

1. Create the PHP script in `plugins/wordpress-trac/scripts/<name>.php`
   - Use `#!/usr/bin/env php` shebang
   - Validate input arguments, write errors to stderr, use exit codes
   - Output Markdown to stdout
   - Follow existing curl/parsing patterns
2. Create the command definition in `plugins/wordpress-trac/commands/<name>.md`
   - Include YAML front-matter with `description`, `allowed-tools`, and `argument-hint`
   - Reference the script as `${CLAUDE_PLUGIN_ROOT}/scripts/<name>.php`
3. Make the script executable: `chmod +x plugins/wordpress-trac/scripts/<name>.php`

## Key Conventions

- **All output is Markdown** — scripts produce Markdown-formatted text for AI consumption
- **Scripts are invoked directly** — never via `php script.php`, always `./script.php` or full path
- **Error handling** — stderr for errors, stdout for output, exit code 1 for failures
- **No external PHP dependencies** — only standard PHP extensions (curl, DOM)
- **Memory efficiency** — stream large responses to `php://temp` rather than loading into memory
- **Idempotent reads** — all scripts are read-only queries against WordPress Trac, no write operations

## Marketplace Configuration

- **`.claude-plugin/plugin.json`** — Identifies the plugin: name (`wordpress-trac`), version (`1.0.0`), license (`MIT`)
- **`.claude-plugin/marketplace.json`** — Registers with the marketplace under the `sirreal` namespace; license listed as `GPL-2.0-or-later`

## Manual Testing

Scripts can be tested directly from the command line (requires PHP 8.4+ with curl):

```bash
# Fetch a ticket
./plugins/wordpress-trac/scripts/ticket.php 12345

# Fetch ticket discussion
./plugins/wordpress-trac/scripts/ticket-discussion.php 12345

# Fetch a changeset
./plugins/wordpress-trac/scripts/changeset.php 61418

# Search tickets
./plugins/wordpress-trac/scripts/search.php --status=new --component="HTML API"

# View search help
./plugins/wordpress-trac/scripts/search.php --help
```
