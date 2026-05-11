# wp-trac-ticket --prs Flag Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `--prs` flag to the `wp-trac-ticket` skill that lists GitHub pull requests associated with a Trac ticket via the `api.wordpress.org` PR endpoint.

**Architecture:** Single-script change. Refactor the existing arg loop to use a single `$mode` variable (`basic`/`discussion`/`prs`), dispatch on it, and add a new PR mode that fetches JSON, sorts, and renders compact markdown. `SKILL.md` is updated to document the new flag.

**Tech Stack:** PHP 8.4 (no dependencies). Uses native curl and `json_decode`. Project has no automated test framework — verification is executable + output inspection.

**Reference spec:** `docs/superpowers/specs/2026-05-11-wp-trac-prs-flag-design.md`

---

## File Map

- **Modify:** `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php`
  - Refactor arg parser (~ lines 24-39 in current file)
  - Add new PR mode block (after discussion block, before existing TSV/basic block)
- **Modify:** `plugins/wordpress-trac/skills/wp-trac-ticket/SKILL.md`
  - `argument-hint`, decision table, Commands section, Output section

No new files. No new directories.

---

### Task 1: Refactor arg parsing to use a single $mode variable

This task is a pure refactor: existing behaviour must not change. It prepares the script to accept the `--prs` flag in Task 2 with genuine last-wins precedence.

**Files:**
- Modify: `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php` (lines 24-53)

- [ ] **Step 1: Read the current arg-parse block to confirm exact contents**

```bash
sed -n '24,53p' plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php
```

Expected (the existing code):
```php
// Parse arguments
$discussion_mode = false;
$ticket_num = null;

for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--discussion') {
        $discussion_mode = true;
    } elseif ($ticket_num === null) {
        // Extract ticket number from input (handle URLs and # prefix)
        if (preg_match('/ticket\/(\d+)/', $argv[$i], $matches)) {
            $ticket_num = $matches[1];
        } else {
            $ticket_num = ltrim($argv[$i], '#');
        }
    }
}

if ($ticket_num === null) {
    fwrite(STDERR, "Usage: ticket.php [--discussion] <ticket-number>\n");
    exit(1);
}
```

- [ ] **Step 2: Replace the arg-parse block with a $mode-based loop**

Use Edit to replace exactly this `old_string`:

```php
// Parse arguments
$discussion_mode = false;
$ticket_num = null;

for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--discussion') {
        $discussion_mode = true;
    } elseif ($ticket_num === null) {
        // Extract ticket number from input (handle URLs and # prefix)
        if (preg_match('/ticket\/(\d+)/', $argv[$i], $matches)) {
            $ticket_num = $matches[1];
        } else {
            $ticket_num = ltrim($argv[$i], '#');
        }
    }
}

if ($ticket_num === null) {
    fwrite(STDERR, "Usage: ticket.php [--discussion] <ticket-number>\n");
    exit(1);
}
```

with this `new_string`:

```php
// Parse arguments
$mode = 'basic';
$ticket_num = null;

for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--discussion') {
        $mode = 'discussion';
    } elseif ($argv[$i] === '--prs') {
        $mode = 'prs';
    } elseif ($ticket_num === null) {
        // Extract ticket number from input (handle URLs and # prefix)
        if (preg_match('/ticket\/(\d+)/', $argv[$i], $matches)) {
            $ticket_num = $matches[1];
        } else {
            $ticket_num = ltrim($argv[$i], '#');
        }
    }
}

if ($ticket_num === null) {
    fwrite(STDERR, "Usage: ticket.php [--discussion | --prs] <ticket-number>\n");
    exit(1);
}
```

- [ ] **Step 3: Replace the discussion-mode dispatch**

Find this line (currently at file line ~53):

```php
if ($discussion_mode) {
```

Replace with:

```php
if ($mode === 'discussion') {
```

- [ ] **Step 4: Run the script in basic mode to verify the refactor**

Run:
```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php 64776
```

Expected: Markdown output starting with `# Trac Ticket #64776` followed by Component/Summary/Type/Status/Milestone metadata and a Description section. No PHP warnings or errors.

- [ ] **Step 5: Run the script in discussion mode to verify it still works**

Run:
```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --discussion 64776
```

Expected: Markdown output starting with `# Trac Ticket #64776 Discussion` followed by `## <author> (comment:N)` sections. No PHP warnings or errors.

- [ ] **Step 6: Run the script with the new flag — should fall through to basic mode for now**

Run:
```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --prs 64776
```

Expected: Because PR mode is not yet implemented, this should fall through and produce basic-mode output for ticket 64776. (The `$mode === 'prs'` case has no handler yet, so execution continues to the TSV-fetch path.) No errors.

- [ ] **Step 7: Commit**

```bash
git add plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php
git commit -m "Refactor ticket.php arg parser to use mode variable"
```

---

### Task 2: Implement PR mode

Add a dispatch block for `$mode === 'prs'` that fetches the wordpress.org PR endpoint, sorts, and renders compact markdown. The block goes immediately before the TSV/basic block (after the existing discussion mode's `exit(0);`).

**Files:**
- Modify: `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php` (insert ~80 new lines)

- [ ] **Step 1: Locate the insertion point**

Run:
```bash
grep -n "^// Fetch ticket data in TSV format" plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php
```

Expected: A single line number near 210 — the comment marking the start of basic mode. The PR mode block will be inserted immediately before this comment.

- [ ] **Step 2: Insert PR mode block**

Use Edit to replace exactly this `old_string`:

```php
// Fetch ticket data in TSV format, streaming directly to a temp file
$url = "https://core.trac.wordpress.org/ticket/{$ticket_num}?format=tab";
```

with this `new_string`:

```php
// PR mode: fetch associated PRs from api.wordpress.org and render markdown
if ($mode === 'prs') {
    $url = "https://api.wordpress.org/dotorg/trac/pr/?trac=core&ticket={$ticket_num}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'wp-trac-ticket/1.0');
    trac_apply_cookie($ch);
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($http_code < 200 || $http_code >= 300) {
        fwrite(STDERR, "Error: Could not fetch PRs for ticket #{$ticket_num} (HTTP {$http_code})\n");
        exit(1);
    }

    $prs = json_decode($body, true);
    if (!is_array($prs)) {
        fwrite(STDERR, "Error: Could not parse PR response for ticket #{$ticket_num}\n");
        exit(1);
    }

    echo "# Trac Ticket #{$ticket_num} Pull Requests\n\n";

    if (count($prs) === 0) {
        echo "_No pull requests found._\n";
        exit(0);
    }

    // Sort: open first, then closed; within each group by updated_at desc.
    usort($prs, function ($a, $b) {
        $aOpen = (($a['state'] ?? '') === 'open') ? 0 : 1;
        $bOpen = (($b['state'] ?? '') === 'open') ? 0 : 1;
        if ($aOpen !== $bOpen) {
            return $aOpen - $bOpen;
        }
        return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
    });

    foreach ($prs as $pr) {
        $number    = $pr['number']       ?? '—';
        $title     = $pr['title']        ?? '—';
        $state     = $pr['state']        ?? '—';
        $author    = $pr['user']['name'] ?? '—';
        $url       = $pr['html_url']     ?? '—';
        $created   = substr($pr['created_at'] ?? '', 0, 10) ?: '—';
        $updated   = substr($pr['updated_at'] ?? '', 0, 10) ?: '—';
        $closedRaw = $pr['closed_at'] ?? null;
        $additions = $pr['changes']['additions'] ?? '—';
        $deletions = $pr['changes']['deletions'] ?? '—';
        $touches   = !empty($pr['touches_tests']) ? 'yes' : 'no';

        echo "## #{$number} — {$title}\n\n";
        echo "- **State:** {$state}\n";
        echo "- **Author:** {$author}\n";
        echo "- **URL:** {$url}\n";
        echo "- **Created:** {$created} · **Updated:** {$updated}\n";
        if ($state === 'closed' && !empty($closedRaw)) {
            echo "- **Closed:** " . substr($closedRaw, 0, 10) . "\n";
        }
        echo "- **Changes:** +{$additions} / -{$deletions} · **Touches tests:** {$touches}\n";

        $checks = $pr['check_runs'] ?? [];
        if (is_array($checks) && count($checks) > 0) {
            $parts = [];
            foreach ($checks as $name => $status) {
                $parts[] = "{$name}: {$status}";
            }
            echo "- **CI:** " . implode(', ', $parts) . "\n";
        }

        $reviews = $pr['reviews'] ?? [];
        if (is_array($reviews) && count($reviews) > 0) {
            $groups = [];
            foreach ($reviews as $reviewState => $users) {
                $groups[] = "{$reviewState}: " . implode(', ', (array)$users);
            }
            echo "- **Reviews:** " . implode('; ', $groups) . "\n";
        } else {
            echo "- **Reviews:** _none_\n";
        }

        echo "\n";
    }

    exit(0);
}

// Fetch ticket data in TSV format, streaming directly to a temp file
$url = "https://core.trac.wordpress.org/ticket/{$ticket_num}?format=tab";
```

- [ ] **Step 3: Verify PR mode on a ticket with PRs**

Run:
```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --prs 64776
```

Expected:
- First line: `# Trac Ticket #64776 Pull Requests`
- One or more `## #<number> — <title>` PR sections
- Each PR section shows State, Author, URL, Created/Updated, Changes/Touches tests, and Reviews lines
- Open PRs (if present) appear before closed PRs
- No PHP warnings or errors

- [ ] **Step 4: Verify basic and discussion modes still work**

Run:
```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php 64776
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --discussion 64776
```

Expected: Both produce their existing output (basic ticket info; discussion comments). No regressions.

- [ ] **Step 5: Verify last-wins flag precedence**

Run:
```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --discussion --prs 64776
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --prs --discussion 64776
```

Expected:
- First command → PR mode output (`# Trac Ticket #64776 Pull Requests`)
- Second command → discussion mode output (`# Trac Ticket #64776 Discussion`)

- [ ] **Step 6: Verify the empty-PRs path**

Pick a ticket known to have no PRs. Older tickets that closed long ago are good candidates — e.g. ticket #1 (the very first WordPress Trac ticket). Run:

```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --prs 1
```

Expected:
```
# Trac Ticket #1 Pull Requests

_No pull requests found._
```

If ticket #1 happens to return PRs, try another likely-empty ticket. If the endpoint returns HTTP 404 instead of an empty array, the script exits 1 with a "Could not fetch PRs" message — that is acceptable behaviour per the spec.

- [ ] **Step 7: Verify error path for invalid ticket numbers**

Run:
```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --prs abc
```

Expected: stderr message `Error: Invalid ticket number: abc` and exit code 1. (This is handled by the existing `ctype_digit` check before any mode runs.)

- [ ] **Step 8: Commit**

```bash
git add plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php
git commit -m "Add --prs mode to wp-trac-ticket script"
```

---

### Task 3: Update SKILL.md

Document the `--prs` flag in the skill manifest so the model picks the right mode based on user phrasing.

**Files:**
- Modify: `plugins/wordpress-trac/skills/wp-trac-ticket/SKILL.md`

- [ ] **Step 1: Update argument-hint in the frontmatter**

Use Edit:

```
old_string: argument-hint: "[--discussion] <ticket-number>"
new_string: argument-hint: "[--discussion | --prs] <ticket-number>"
```

- [ ] **Step 2: Rename the decision table section heading and expand it**

Use Edit to replace exactly this `old_string`:

```
## When to use --discussion

| User says | Use --discussion? |
|-----------|-------------------|
| "look up ticket 30000" | No |
| "what's the status of ticket 12345" | No |
| "what are people saying on ticket 30000" | Yes |
| "show me the comments on #62345" | Yes |
| "summarize the discussion on ticket 50000" | Yes |
```

with this `new_string`:

```
## Choosing a mode

Three modes are available. Pick one based on what the user wants.

| User says | Mode |
|-----------|------|
| "look up ticket 30000" | basic (no flag) |
| "what's the status of ticket 12345" | basic (no flag) |
| "what are people saying on ticket 30000" | `--discussion` |
| "show me the comments on #62345" | `--discussion` |
| "summarize the discussion on ticket 50000" | `--discussion` |
| "show me the PRs for ticket 64776" | `--prs` |
| "what pull requests are open for #62345" | `--prs` |
| "any code submitted for ticket 50000?" | `--prs` |

Modes are exclusive. If both `--discussion` and `--prs` are passed, the last one on the command line wins.
```

- [ ] **Step 3: Extend the Commands section with the `--prs` command**

Use Edit to replace exactly this `old_string`:

```
## Commands

- Basic info: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` <ticket-number>
- With comments: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` --discussion <ticket-number>
```

with this `new_string`:

```
## Commands

- Basic info: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` <ticket-number>
- With comments: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` --discussion <ticket-number>
- With PRs: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` --prs <ticket-number>
```

- [ ] **Step 4: Extend the Output section**

Use Edit to replace exactly this `old_string`:

```
## Output

Basic mode returns: id, component, summary, type, status, milestone, and description.

Discussion mode returns: a list of comments with author and content for each.
```

with this `new_string`:

```
## Output

Basic mode returns: id, component, summary, type, status, milestone, and description.

Discussion mode returns: a list of comments with author and content for each.

PRs mode returns: a list of associated GitHub pull requests sorted with open first then closed, each by most-recent update. For each PR: number, title, state, author, URL, created/updated/closed dates, additions/deletions, whether it touches tests, CI status (when present), and review status.
```

- [ ] **Step 5: Re-read SKILL.md and verify it makes sense end-to-end**

Run:
```bash
cat plugins/wordpress-trac/skills/wp-trac-ticket/SKILL.md
```

Expected: Frontmatter `argument-hint` reflects both flags. "Choosing a mode" table has rows for all three modes. Commands section lists three bullets. Output section has three paragraphs.

- [ ] **Step 6: Commit**

```bash
git add plugins/wordpress-trac/skills/wp-trac-ticket/SKILL.md
git commit -m "Document --prs flag in wp-trac-ticket SKILL.md"
```

---

### Task 4: End-to-end smoke test through the plugin harness

Verify the skill works when loaded as a plugin (not just calling the script directly).

**Files:** None modified.

- [ ] **Step 1: Launch a local plugin session**

Run:
```bash
claude --plugin-dir ./plugins/wordpress-trac
```

- [ ] **Step 2: Issue a PR-style request inside the session**

Type at the prompt:
```
show me the PRs for ticket 64776
```

Expected: The model invokes `wp-trac-ticket` with `--prs 64776` (you should see the `allowed-tools` Bash invocation), and the rendered output matches the format described in the spec.

- [ ] **Step 3: Issue a basic-mode request to confirm no regression**

Type at the prompt:
```
what's the status of ticket 64776
```

Expected: Model invokes the script without `--prs` and shows the basic ticket info.

- [ ] **Step 4: Exit the session**

This task makes no code changes. Nothing to commit.

---

## Self-review notes

- Spec coverage: every spec section maps to a task. `--prs` flag wiring → Task 1. Endpoint + sort + render + edge cases → Task 2. SKILL.md updates → Task 3. Manual testing → verification steps in Tasks 1, 2, 3, plus end-to-end harness in Task 4.
- No placeholders. Every code-changing step contains the actual code or the exact Edit `old_string`/`new_string`.
- Type/identifier consistency: `$mode` values are `'basic' | 'discussion' | 'prs'` in every task. The PR endpoint URL appears once. The output field set is the same in spec and Task 2 code.
- The project has no test framework. The spec explicitly calls out manual verification. The plan follows suit with run-and-inspect verification steps that mirror TDD's red/green discipline (Step 4 in Task 1 confirms basic mode still works before changes; Task 2 Step 3 confirms the new mode works after changes).
