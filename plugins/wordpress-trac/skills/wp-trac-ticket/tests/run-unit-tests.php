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
