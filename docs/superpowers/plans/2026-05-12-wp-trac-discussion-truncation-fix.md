# wp-trac-ticket Discussion Truncation Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Post-execution note:** During implementation the test runners were relocated from `plugins/wordpress-trac/skills/wp-trac-ticket/tests/` to `tests/wp-trac-ticket/` (repo-root tests dir, so they don't ship to plugin users). Path references below are preserved as historical record of the original plan.

**Goal:** Fix silent content loss in `ticket.php --discussion <n>` so comment bodies render in full, and surface parse failures loudly instead of falling back to `strip_tags`.

**Architecture:** Replace the SimpleXML-based HTML→markdown walker with one built on PHP 8.4's native `Dom\HTMLDocument`, which the rest of this plugin already uses (`changeset.php:55`). DOM exposes interleaved text nodes via `childNodes`, fixing both the mixed-content drop (defect 1) and the `<pre>`-only-first-text-node drop (defect 2). Extract the converter into a small `require_once`-able file so it can be unit-tested without going over the network.

**Tech Stack:** PHP 8.4 (`Dom\HTMLDocument`, `LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD`); plain-PHP test runner (no Composer, matches repo's "no Composer dependencies" rule per `CLAUDE.md`). Subprocess tests use `proc_open` with array argv — no shell, no injection surface.

**Source of truth for defects:** `/tmp/claude-501/response.md` (handoff report). Treat that document's test cases T1–T10, I1–I5, N1–N2 as the acceptance spec for this plan.

---

## File Structure

**Modify:**
- `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php`
  - Remove `convertXHTMLToMarkdown` (lines 119–132) and `convertXMLNode` (lines 137–209) from this file.
  - Replace with `require_once __DIR__ . '/html-to-markdown.php';` plus the existing call site at line 101.
  - Remove `html_entity_decode` at line 100 if step 3 of Task 4 confirms it's no longer needed; otherwise leave it as a narrow safety net.

**Create:**
- `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/html-to-markdown.php` — the new DOM-based converter. Exports `convertXHTMLToMarkdown(string $html): string`. No top-level side effects (safe to `require_once` from tests and from the main script).
- `plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-unit-tests.php` — executable PHP test runner for T1–T10. Exits non-zero on failure, prints a diff-style report.
- `plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-integration-tests.php` — executable test runner for I1, I3, I4 and N1, N2. Requires network and a Trac cookie; skips cleanly (exit 0 with a `SKIP:` log line) when offline.

**Rationale for splitting into three files:** the converter is logic, the unit runner is fast/deterministic, the integration runner is slow/network. Splitting means the engineer (and CI later) can run the fast tier on every save without paying the network cost.

---

## Pre-flight

- [ ] **P1: Confirm environment**

Run:
```bash
php --version
php -r 'var_dump(class_exists("Dom\\HTMLDocument"));'
test -f ~/.config/wp-trac/cookie && echo OK || echo "NO COOKIE - integration tests will skip"
```
Expected: PHP ≥ 8.4, `bool(true)`, `OK` (or a `NO COOKIE` note — that's fine, unit tests don't need it).

- [ ] **P2: Read the handoff**

Read `/tmp/claude-501/response.md` end-to-end. The test cases T1–T10 are the spec. Do not invent your own; use those.

- [ ] **P3: Read the current converter**

Read `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php` lines 99–209. You should be able to explain in one sentence why lines 200–205 drop content (the `$node->count() === 0` gate). If you can't, re-read until you can.

---

## Task 1: Extract converter to its own file (no behaviour change yet)

Pure move. We are NOT fixing anything in this task — we are getting the code into a shape where it can be unit-tested.

**Files:**
- Create: `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/html-to-markdown.php`
- Modify: `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php` (lines 100–132, 137–209)

- [ ] **Step 1: Create `html-to-markdown.php` with current (defective) implementation verbatim**

```php
<?php
/**
 * Convert XHTML string from Trac RSS to markdown.
 *
 * NOTE: This file is being replaced. Current implementation is the
 * defective SimpleXML-based walker; subsequent tasks rewrite it.
 */

function convertXHTMLToMarkdown(string $html): string {
    $html = trim($html);
    if (empty($html)) {
        return '';
    }

    $xml = @simplexml_load_string("<root>{$html}</root>", 'SimpleXMLElement', LIBXML_NOERROR);
    if ($xml === false) {
        return strip_tags($html);
    }

    return convertXMLNode($xml);
}

function convertXMLNode(SimpleXMLElement $node): string {
    $result = '';

    foreach ($node->children() as $name => $child) {
        $inner = convertXMLNode($child);
        $text = (string)$child;

        switch (strtolower($name)) {
            case 'br':
                $result .= "\n";
                break;
            case 'p':
                $result .= "\n\n" . ($inner ?: $text) . "\n\n";
                break;
            case 'code':
                $result .= "`{$text}`";
                break;
            case 'pre':
                $class = (string)$child['class'];
                $lang = '';
                if ($class && preg_match('/\bwiki-code-(\w+)\b/', $class, $matches)) {
                    $lang = $matches[1];
                }
                $result .= "\n\n```{$lang}\n" . trim($text) . "\n```\n\n";
                break;
            case 'a':
                $href = (string)$child['href'];
                $linkText = $inner ?: $text;
                if ($href && str_starts_with($href, '/')) {
                    $href = "https://core.trac.wordpress.org{$href}";
                }
                if (!empty($href) && !empty($linkText)) {
                    $result .= "[{$linkText}]({$href})";
                } else {
                    $result .= $linkText;
                }
                break;
            case 'strong':
            case 'b':
                $result .= '**' . ($inner ?: $text) . '**';
                break;
            case 'em':
            case 'i':
                $result .= '_' . ($inner ?: $text) . '_';
                break;
            case 'ul':
            case 'ol':
                $result .= "\n" . $inner . "\n";
                break;
            case 'li':
                $result .= "- " . ($inner ?: $text) . "\n";
                break;
            case 'blockquote':
                $quoted = $inner ?: $text;
                $quoted = preg_replace('/^/m', '> ', $quoted);
                $result .= "\n" . $quoted . "\n";
                break;
            default:
                $result .= $inner ?: $text;
                break;
        }
    }

    $directText = trim((string)$node);
    if (!empty($directText) && $node->count() === 0) {
        $result .= $directText;
    }

    return preg_replace('/\n{3,}/', "\n\n", trim($result, " \t\n\r\f"));
}
```

- [ ] **Step 2: Remove those two functions from `ticket.php` and `require_once` the new file**

In `ticket.php`, delete lines 116–209 (the two function definitions plus their docblocks). Replace with:

```php
require_once __DIR__ . '/html-to-markdown.php';
```

Put the `require_once` near the top of the file, just below the `trac_apply_cookie` definition (around line 23). The call site at line 101 (`$description = convertXHTMLToMarkdown($description);`) is unchanged.

- [ ] **Step 3: Smoke-test the move with a real ticket**

Run:
```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --discussion 50040 | wc -c
```
Expected: ~3,419 bytes (the same broken output as before — we have not fixed anything yet, just relocated code). Anything wildly different means the extraction broke something.

- [ ] **Step 4: Commit**

```bash
git add plugins/wordpress-trac/skills/wp-trac-ticket/scripts/html-to-markdown.php \
        plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php
git commit -m "refactor(wp-trac-ticket): extract html→markdown converter to its own file"
```

---

## Task 2: Build the unit test harness (red — all tests should currently fail)

Now that the converter is loadable, write the T1–T10 tests from the handoff. They will fail against the current implementation — that's the point. This is the **red** phase.

**Files:**
- Create: `plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-unit-tests.php`

- [ ] **Step 1: Create the test runner**

```php
#!/usr/bin/env php
<?php
/**
 * Unit tests for html-to-markdown.php.
 * Runs T1–T10 from the discussion-truncation handoff.
 *
 * Usage: ./run-unit-tests.php
 * Exit: 0 on all pass, 1 on any failure.
 */

require_once __DIR__ . '/../scripts/html-to-markdown.php';

$failures = 0;
$tests = 0;

function check(string $label, string $input, array $must_contain, array $must_not_contain = []): void {
    global $failures, $tests;
    $tests++;
    $out = convertXHTMLToMarkdown($input);
    $missing = [];
    foreach ($must_contain as $needle) {
        if (strpos($out, $needle) === false) {
            $missing[] = $needle;
        }
    }
    $present = [];
    foreach ($must_not_contain as $needle) {
        if (strpos($out, $needle) !== false) {
            $present[] = $needle;
        }
    }
    if (!$missing && !$present) {
        fwrite(STDOUT, "PASS  {$label}\n");
        return;
    }
    $failures++;
    fwrite(STDOUT, "FAIL  {$label}\n");
    fwrite(STDOUT, "  input:  " . substr($input, 0, 200) . "\n");
    fwrite(STDOUT, "  output: " . substr($out, 0, 400) . "\n");
    if ($missing) fwrite(STDOUT, "  missing: " . implode(' | ', $missing) . "\n");
    if ($present) fwrite(STDOUT, "  unexpected: " . implode(' | ', $present) . "\n");
}

// T1: mixed content in paragraph
check('T1 mixed-content paragraph',
    '<p>Header text <a href="/x">link</a> trailing text</p>',
    ['Header text', 'link', 'trailing text', 'https://core.trac.wordpress.org/x']);

// T2: text before nested list
check('T2 text before nested list',
    '<p>Steps taken<ol><li>first</li><li>second</li></ol></p>',
    ['Steps taken', 'first', 'second']);

// T3: text between block elements
check('T3 text between block elements',
    '<div>intro<ul><li>a</li></ul>outro</div>',
    ['intro', 'a', 'outro']);

// T4: <pre> with nested <br>
check('T4 pre with nested br',
    '<pre class="wiki-code-php">line 1<br/>line 2<br/>line 3</pre>',
    ['line 1', 'line 2', 'line 3', '```php', '```']);

// T5: <pre> with nested span (Trac syntax highlighting)
check('T5 pre with nested span',
    '<pre class="wiki-code-php"><span class="kw">function</span> foo() { <span class="str">\'bar\'</span>; }</pre>',
    ['function', 'foo()', "'bar'"]);

// T6: <pre> with nested anchor (Trac ticket auto-link)
check('T6 pre with nested anchor',
    '<pre>See <a href="/ticket/29420">#29420</a> for context.</pre>',
    ['See', '#29420', 'for context']);

// T7: deeply nested mixed
check('T7 blockquote with mixed inline',
    '<blockquote><p>quoted <em>important</em> text</p></blockquote>',
    ['quoted', 'important', 'text', '>']);

// T8: empty paragraph
check('T8 empty paragraph does not throw or emit null',
    '<p></p>',
    [],
    ['null', 'NULL']);

// T9: regression — links inside paragraphs (case that already worked)
check('T9 link inside paragraph (regression)',
    '<p><a href="https://example.com">click</a></p>',
    ['[click](https://example.com)']);

// T10: parse failure should not silently produce empty output
$brokenInput = '<p>unclosed paragraph with bad markup <foo';
$brokenOut = convertXHTMLToMarkdown($brokenInput);
$tests++;
if (strpos($brokenOut, 'unclosed paragraph with bad markup') !== false) {
    fwrite(STDOUT, "PASS  T10 broken markup renders visible text\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  T10 broken markup silently lost content\n");
    fwrite(STDOUT, "  output: '" . substr($brokenOut, 0, 200) . "'\n");
}

fwrite(STDOUT, "\n{$tests} tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Make it executable and run it**

```bash
chmod +x plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-unit-tests.php
./plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-unit-tests.php
```

Expected: Several FAILs. Specifically T1, T2, T3, T4, T5, T6, T7 should fail against the current implementation. T9 should pass. T8 should pass (it's a guard, not a regression target). T10 will likely fail (silent strip_tags fallback).

Record which tests fail in your scratch notes — this is your baseline.

- [ ] **Step 3: Commit the failing tests**

```bash
git add plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-unit-tests.php
git commit -m "test(wp-trac-ticket): add failing T1-T10 tests for html→markdown converter

Captures defects described in the discussion-truncation handoff:
mixed-content drop, <pre> nested-element drop, silent parse fallback."
```

---

## Task 3: Rewrite the converter with `Dom\HTMLDocument` (green)

Now make the tests pass. This is the core fix.

**Files:**
- Modify: `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/html-to-markdown.php`

- [ ] **Step 1: Replace the file contents with the DOM-based implementation**

```php
<?php
/**
 * Convert XHTML string from Trac RSS to markdown.
 *
 * Uses Dom\HTMLDocument (PHP 8.4+) so interleaved text nodes between
 * element children are preserved — SimpleXML hides text nodes, which
 * caused widespread silent content loss in comment bodies.
 */

function convertXHTMLToMarkdown(string $html): string {
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $wrapped = "<div>{$html}</div>";
    $doc = @Dom\HTMLDocument::createFromString(
        $wrapped,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    if ($doc === false) {
        fwrite(STDERR, "wp-trac-ticket: warning — failed to parse HTML fragment\n");
        return strip_tags($html);
    }

    $root = $doc->getElementsByTagName('div')->item(0);
    if ($root === null) {
        fwrite(STDERR, "wp-trac-ticket: warning — no root element after parse\n");
        return strip_tags($html);
    }

    $out = convertDomNode($root);
    return preg_replace('/\n{3,}/', "\n\n", trim($out, " \t\n\r\f"));
}

function convertDomNode(Dom\Node $node): string {
    $result = '';

    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $result .= $child->textContent;
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }

        /** @var Dom\Element $child */
        $name = strtolower($child->localName);

        switch ($name) {
            case 'br':
                $result .= "\n";
                break;
            case 'p':
                $result .= "\n\n" . convertDomNode($child) . "\n\n";
                break;
            case 'code':
                $result .= '`' . $child->textContent . '`';
                break;
            case 'pre':
                $class = $child->getAttribute('class') ?? '';
                $lang = '';
                if ($class !== '' && preg_match('/\bwiki-code-(\w+)\b/', $class, $matches)) {
                    $lang = $matches[1];
                }
                $result .= "\n\n```{$lang}\n" . trim(preFlatten($child)) . "\n```\n\n";
                break;
            case 'a':
                $href = $child->getAttribute('href') ?? '';
                $text = convertDomNode($child);
                if ($href !== '' && str_starts_with($href, '/')) {
                    $href = "https://core.trac.wordpress.org{$href}";
                }
                if ($href !== '' && $text !== '') {
                    $result .= "[{$text}]({$href})";
                } else {
                    $result .= $text;
                }
                break;
            case 'strong':
            case 'b':
                $result .= '**' . convertDomNode($child) . '**';
                break;
            case 'em':
            case 'i':
                $result .= '_' . convertDomNode($child) . '_';
                break;
            case 'ul':
            case 'ol':
                $result .= "\n" . convertDomNode($child) . "\n";
                break;
            case 'li':
                $result .= '- ' . trim(convertDomNode($child)) . "\n";
                break;
            case 'blockquote':
                $inner = trim(convertDomNode($child));
                $quoted = preg_replace('/^/m', '> ', $inner);
                $result .= "\n" . $quoted . "\n";
                break;
            default:
                $result .= convertDomNode($child);
                break;
        }
    }

    return $result;
}

/**
 * Flatten a <pre> subtree to plain text while converting <br> to newlines.
 * Nested elements (Trac auto-links, syntax-highlighting spans) collapse
 * to their text content — we want the raw code, not its HTML decoration.
 */
function preFlatten(Dom\Node $node): string {
    $out = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $out .= $child->textContent;
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            /** @var Dom\Element $child */
            if (strtolower($child->localName) === 'br') {
                $out .= "\n";
            } else {
                $out .= preFlatten($child);
            }
        }
    }
    return $out;
}
```

- [ ] **Step 2: Run the unit tests**

```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-unit-tests.php
```

Expected: T1–T9 PASS. T10 should also PASS — `Dom\HTMLDocument::createFromString` is a forgiving HTML parser and will recover text from the deliberately-broken input.

If any of T1–T9 fail, debug *before* moving on. Don't paper over.

- [ ] **Step 3: Smoke-test against the real ticket**

```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --discussion 50040 | wc -c
```

Expected: substantially larger than the 3,419-byte baseline. The handoff says comment bodies sum to ~22.6 KB raw; after markdown overhead, expect ≥ 15 KB. If you get a number close to 3.4 KB, the fix didn't land — debug before continuing.

Also spot-check `<pre>` block balance:
```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --discussion 50040 | grep -c '^```'
```
Expected: even number, ≥ 2 (every opening fence must have a closer).

- [ ] **Step 4: Commit**

```bash
git add plugins/wordpress-trac/skills/wp-trac-ticket/scripts/html-to-markdown.php
git commit -m "fix(wp-trac-ticket): use Dom\\HTMLDocument for comment HTML→markdown

SimpleXML's children() iterator hid interleaved text nodes, causing
widespread silent content loss in comment bodies. Mixed-content text
between elements was dropped (defect 1), and <pre> blocks with any
nested element (Trac auto-links, syntax-highlighting spans) emitted
only the first text node (defect 2).

Rewrites the converter on Dom\\HTMLDocument (PHP 8.4+, already used
by changeset.php) which exposes text nodes via childNodes. <pre>
blocks now flatten the full subtree, converting <br> to newlines and
collapsing nested elements to their textContent."
```

---

## Task 4: Drop the now-unnecessary `html_entity_decode` (decision gate)

The handoff notes: *"SimpleXML already decodes XML entities; the description content arriving at that point is HTML."* With DOM, the parser also decodes entities. The pre-decode at `ticket.php:100` may now be double-decoding.

This task is gated on a measurement — if measurement says "still needed," skip the change.

**Files:**
- Modify: `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php` (line 100)

- [ ] **Step 1: Capture before-output**

```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --discussion 50040 > /tmp/before.md
```

- [ ] **Step 2: Remove the decode**

In `ticket.php`, change line 100 from:
```php
$description = html_entity_decode((string)$item->description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
```
to:
```php
$description = (string)$item->description;
```

- [ ] **Step 3: Re-run and diff**

```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --discussion 50040 > /tmp/after.md
diff /tmp/before.md /tmp/after.md | head -60
```

Decision:
- If diff shows literal `&amp;` / `&lt;` appearing in `/tmp/after.md` where `/tmp/before.md` had `&` / `<` — the decode IS still needed (RSS double-encodes inside `<description>`). **Revert the change** and skip step 4.
- If diff is empty, or shows `/tmp/after.md` is *more* correct (entities like `&amp;amp;` collapsing to `&amp;`) — **keep the change**.

- [ ] **Step 4: Commit (only if the change is kept)**

```bash
git add plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php
git commit -m "fix(wp-trac-ticket): drop redundant html_entity_decode

SimpleXML already decodes XML entities when stringifying the
<description> field; Dom\\HTMLDocument decodes HTML entities on
parse. Pre-decoding caused double-decoding for things like &amp;amp;."
```

---

## Task 5: Integration tests against live Trac (I1, I3, I4)

These are the regression net. They require network + cookie. Uses `proc_open` with array argv — no shell, no injection surface.

**Files:**
- Create: `plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-integration-tests.php`

- [ ] **Step 1: Write the integration runner**

```php
#!/usr/bin/env php
<?php
/**
 * Integration tests against live core.trac.wordpress.org.
 *
 * Skips with exit 0 if cookie/network unavailable so this is safe
 * to call from CI that may not have Trac credentials.
 */

$script = realpath(__DIR__ . '/../scripts/ticket.php');

$cookie = getenv('TRAC_COOKIE_FILE') ?: (getenv('HOME') . '/.config/wp-trac/cookie');
if (!is_readable($cookie)) {
    fwrite(STDOUT, "SKIP: no Trac cookie at {$cookie} — integration tests skipped\n");
    exit(0);
}

$failures = 0;
$tests = 0;

/**
 * Run ticket.php with the given argv; return [stdout-bytes, stdout, stderr, exit-code].
 * Uses proc_open with array argv so there is no shell layer.
 */
function run(array $args): array {
    global $script;
    $argv = array_merge([$script], $args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($argv, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return [0, '', '', -1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return [strlen($stdout), $stdout, $stderr, $exit];
}

// I1: content-volume sanity for #50040
$tests++;
[$len, $out, , ] = run(['--discussion', '50040']);
if ($len >= 15000) {
    fwrite(STDOUT, "PASS  I1 #50040 discussion length {$len} >= 15000\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  I1 #50040 discussion length {$len} < 15000\n");
}

// I3: code fences in #50040 are balanced (>= 2 markers, even count)
$tests++;
$fences = substr_count($out, "\n```");
if ($fences >= 2 && $fences % 2 === 0) {
    fwrite(STDOUT, "PASS  I3 #50040 has {$fences} balanced code-fence markers\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  I3 #50040 fence count {$fences} (need even and >= 2)\n");
}

// I2 lite: #50040 comment 3 should contain PHP keywords from the embedded plugin code
$tests++;
if (preg_match('/\b(function|define|class)\b/', $out)) {
    fwrite(STDOUT, "PASS  I2 #50040 output contains PHP keywords from embedded code\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  I2 #50040 output missing PHP keywords — <pre> walker may have regressed\n");
}

// I4: control tickets — each must produce non-trivial output.
foreach ([29420, 50040] as $ticket) {
    $tests++;
    [$len, , , ] = run(['--discussion', (string)$ticket]);
    if ($len >= 500) {
        fwrite(STDOUT, "PASS  I4 #{$ticket} produces {$len} bytes (>=500)\n");
    } else {
        $failures++;
        fwrite(STDOUT, "FAIL  I4 #{$ticket} produced only {$len} bytes\n");
    }
}

fwrite(STDOUT, "\n{$tests} tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Make executable and run**

```bash
chmod +x plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-integration-tests.php
./plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-integration-tests.php
```

Expected: all PASS. If your local cookie is stale, re-auth and retry — do not paper over.

- [ ] **Step 3: Commit**

```bash
git add plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-integration-tests.php
git commit -m "test(wp-trac-ticket): integration tests against live Trac for #50040 + control"
```

---

## Task 6: Loud failure on missing cookie / bad RSS (N1, N2)

Currently, an unauthenticated request returns a login HTML page; the script parses it as RSS, finds no `<item>` elements, and prints `_No comments found._` — misleading.

**Files:**
- Modify: `plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php` (around lines 72–85)
- Modify: `plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-integration-tests.php` (append N2)

- [ ] **Step 1: Add a sanity check on the RSS root element**

In `ticket.php`, after the `simplexml_load_string` call (line 72), before `$items = $xml->channel->item;`, insert:

```php
// Real RSS has a <channel> under <rss>. The Trac auth challenge page
// parses as XML but lacks <channel>, which previously silently became
// "_No comments found._" — masking a missing cookie as an empty thread.
if (!isset($xml->channel)) {
    fwrite(STDERR, "Error: response for ticket #{$ticket_num} is not RSS — likely auth required (no cookie at \$TRAC_COOKIE_FILE or ~/.config/wp-trac/cookie)\n");
    exit(1);
}
```

- [ ] **Step 2: Append the N2 test to `run-integration-tests.php`**

Insert before the final summary `fwrite`:

```php
// N2: missing-cookie path. Temporarily point TRAC_COOKIE_FILE at /dev/null
// so the script's auth path bypasses the real cookie, then assert clear failure.
$tests++;
$origEnv = getenv('TRAC_COOKIE_FILE');
putenv('TRAC_COOKIE_FILE=/dev/null');
[$len, $stdout, $stderr, $exit] = run(['--discussion', '50040']);
if ($origEnv === false) {
    putenv('TRAC_COOKIE_FILE');
} else {
    putenv('TRAC_COOKIE_FILE=' . $origEnv);
}
if ($exit !== 0 && (stripos($stderr, 'auth') !== false || stripos($stderr, 'not rss') !== false)) {
    fwrite(STDOUT, "PASS  N2 missing cookie produces clear error\n");
} else {
    $failures++;
    fwrite(STDOUT, "FAIL  N2 missing cookie did not produce clear error (exit={$exit}, stderr={$stderr})\n");
}
```

Note: this test relies on `/dev/null` being unreadable as a cookie file (the `is_readable` check in `trac_apply_cookie` returns false for `/dev/null` on most systems because reading it gives empty content; the `trim($cookie) === ''` branch then skips the curl header). Verify in step 3 — if it does not behave as expected, fall back to temporarily renaming the real cookie inside the test (`rename($cookie, $cookie . '.bak')` then `rename` back in a `try/finally`).

- [ ] **Step 3: Run both tiers**

```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-unit-tests.php && \
./plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-integration-tests.php
```
Both should be all-PASS. If N2 doesn't behave, switch to the rename-based approach noted above.

- [ ] **Step 4: Commit**

```bash
git add plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php \
        plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-integration-tests.php
git commit -m "fix(wp-trac-ticket): error loudly when RSS fetch returns non-RSS

Trac's auth challenge page parses as XML but lacks <channel>. The
previous code silently degraded to '_No comments found._', masking
a missing cookie as a benign empty discussion."
```

---

## Task 7: Final validation

- [ ] **Step 1: Run the whole tier**

```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-unit-tests.php && \
./plugins/wordpress-trac/skills/wp-trac-ticket/tests/run-integration-tests.php && \
echo "ALL GREEN"
```

Expected: `ALL GREEN`.

- [ ] **Step 2: Manually eyeball #50040**

```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --discussion 50040 | less
```

Walk through every comment. The handoff says comment 3 should contain a plugin code dump in a fenced block, and comment 5's `<pre>` should be properly closed. Confirm visually.

- [ ] **Step 3: Quick regression on basic + PR modes**

These modes don't use `convertXHTMLToMarkdown`, but a botched refactor could have broken the `require_once` placement. Sanity-check:

```bash
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php 50040 | head -10
./plugins/wordpress-trac/skills/wp-trac-ticket/scripts/ticket.php --prs 50040 | head -10
```

Both should produce reasonable output and exit 0.

- [ ] **Step 4: If working from a feature branch, prepare PR via the `commit-commands:commit-push-pr` skill** (out of scope here — the user will direct).

---

## Out of scope (do not do)

- **Sibling scripts:** `changeset.php`, `timeline.php`, `search.php` do not import `convertXHTMLToMarkdown` (verified during pre-flight). Don't touch them.
- **Markdown output format changes:** The handoff is explicit — preserve existing conventions for cases that already work. Don't "improve" the markdown formatting beyond what fixing the loss requires.
- **PHPUnit / Composer:** Repo rule (`CLAUDE.md`): no Composer dependencies. The bespoke runner is intentional.

---

## Self-review notes

Coverage map (handoff acceptance criteria → task):

| Criterion | Where covered |
| --- | --- |
| T1–T9 unit tests pass | Task 2 (write) + Task 3 (fix) |
| T10 surfaces parse failures | Task 2 step 1 + Task 3 step 2 |
| I1 (#50040 ≥ 15 KB) | Task 5 step 1 |
| I2 (comment 3 code dump present) | Task 5 step 1 ("I2 lite") |
| I3 (balanced fences) | Task 5 step 1 |
| I4 (regression on control tickets) | Task 5 step 1 |
| strip_tags fallback no longer hit on well-formed RSS | Task 3 step 1 (DOM parser tolerates malformed HTML; fallback now logs to STDERR) |
| Network-dependent tests skip cleanly | Task 5 step 1 (cookie absence → exit 0 SKIP) |
| N1/N2 loud failure on bad fetch | Task 6 |

Out-of-scope from handoff (deliberately not covered):

| Criterion | Why skipped |
| --- | --- |
| I5 (round-trip ≥ 90% text preservation) | Adds fuzzy similarity logic that's brittle and overlaps with I1's volume check. Not worth the complexity for a single-file plugin. Re-add later if I1 misses a regression. |

Open judgment calls flagged for the executing agent:

1. **Task 4 (drop `html_entity_decode`):** decision is bisect-test-driven. Either outcome is fine; revert if double-decode test fails.
2. **Task 6 step 2 (N2 cookie-bypass mechanism):** `TRAC_COOKIE_FILE=/dev/null` is the cleaner approach but may not behave the same on every OS. If it doesn't, the rename-based fallback is documented inline. Don't invent a third path.
3. **List item formatting:** the new walker emits `- ` (hyphen + space) for `<li>`. The old code did the same. If "match existing output conventions" surfaces a difference (e.g. blank line between items), match the old output exactly rather than the new walker's natural output.
