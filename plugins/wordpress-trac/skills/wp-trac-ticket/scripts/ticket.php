#!/usr/bin/env php
<?php
/**
 * Fetch WordPress Trac ticket info as markdown.
 *
 * Usage: ticket.php [--short] <ticket-number>
 *
 * Default: metadata + description + attachments + changesets + discussion + PRs.
 * --short: metadata + description only.
 */

require_once __DIR__ . '/html-to-markdown.php';

function trac_apply_cookie($ch): void {
    $file = getenv('TRAC_COOKIE_FILE');
    if ($file === false || $file === '') {
        $home = getenv('XDG_CONFIG_HOME') ?: (getenv('HOME') . '/.config');
        $file = $home . '/wp-trac/cookie';
    }
    if (!is_readable($file)) {
        return;
    }
    $cookie = trim(file_get_contents($file));
    if ($cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
}

// Parse arguments
$short = false;
$ticket_num = null;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--short') {
        $short = true;
    } elseif ($ticket_num === null) {
        if (preg_match('/ticket\/(\d+)/', $argv[$i], $matches)) {
            $ticket_num = $matches[1];
        } else {
            $ticket_num = ltrim($argv[$i], '#');
        }
    }
}

if ($ticket_num === null || !ctype_digit($ticket_num)) {
    fwrite(STDERR, "Usage: ticket.php [--short] <ticket-number>\n");
    exit(1);
}

// Build endpoints. TSV always; RSS + PR only if not --short.
$tsv_url = "https://core.trac.wordpress.org/ticket/{$ticket_num}?format=tab";
$rss_url = "https://core.trac.wordpress.org/ticket/{$ticket_num}?format=rss";
$pr_url  = "https://api.wordpress.org/dotorg/trac/pr/?trac=core&ticket={$ticket_num}";

$tsv_stream = fopen('php://temp', 'r+');
$tsv_ch = curl_init($tsv_url);
curl_setopt($tsv_ch, CURLOPT_FILE, $tsv_stream);
curl_setopt($tsv_ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($tsv_ch, CURLOPT_USERAGENT, 'wp-trac-ticket/2.0');
trac_apply_cookie($tsv_ch);

$mh = curl_multi_init();
curl_multi_add_handle($mh, $tsv_ch);

$rss_ch = null;
$pr_ch  = null;
if (!$short) {
    $rss_ch = curl_init($rss_url);
    curl_setopt($rss_ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($rss_ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($rss_ch, CURLOPT_USERAGENT, 'wp-trac-ticket/2.0');
    trac_apply_cookie($rss_ch);
    curl_multi_add_handle($mh, $rss_ch);

    // The PR endpoint lives on api.wordpress.org, not core.trac.wordpress.org.
    // Do NOT apply the Trac cookie here — CURLOPT_COOKIE is not host-scoped
    // and would leak the trac_auth token to a different origin.
    $pr_ch = curl_init($pr_url);
    curl_setopt($pr_ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($pr_ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($pr_ch, CURLOPT_USERAGENT, 'wp-trac-ticket/2.0');
    curl_multi_add_handle($mh, $pr_ch);
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh);
    }
} while ($running > 0);

$tsv_code = curl_getinfo($tsv_ch, CURLINFO_HTTP_CODE);
curl_multi_remove_handle($mh, $tsv_ch);

$rss_body = null;
$rss_code = null;
if ($rss_ch !== null) {
    $rss_body = curl_multi_getcontent($rss_ch);
    $rss_code = curl_getinfo($rss_ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $rss_ch);
}

$pr_body = null;
$pr_code = null;
if ($pr_ch !== null) {
    $pr_body = curl_multi_getcontent($pr_ch);
    $pr_code = curl_getinfo($pr_ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $pr_ch);
}
curl_multi_close($mh);

if ($tsv_code < 200 || $tsv_code >= 300) {
    fwrite(STDERR, "Error: Could not fetch ticket #{$ticket_num} (HTTP {$tsv_code})\n");
    exit(1);
}

// Parse TSV
rewind($tsv_stream);
$headers = fgetcsv($tsv_stream, 0, "\t", '"', '');
$values = fgetcsv($tsv_stream, 0, "\t", '"', '');
fclose($tsv_stream);

if ($headers === false || $values === false) {
    fwrite(STDERR, "Error: Invalid response for ticket #{$ticket_num}\n");
    exit(1);
}
$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
if (count($headers) !== count($values)) {
    fwrite(STDERR, "Error: Malformed TSV data for ticket #{$ticket_num}\n");
    exit(1);
}
$ticket = array_combine($headers, $values);

// Convert Trac-wiki syntax in description to markdown.
$description = $ticket['description'] ?? '';
$description = preg_replace_callback(
    '/^\{\{\{(?:#!(\w+))?\r?$/m',
    function ($matches) {
        $lang = $matches[1] ?? '';
        if ($lang === 'xml') $lang = 'html';
        return '```' . $lang;
    },
    $description
);
$description = preg_replace('/^\}\}\}\r?$/m', '```', $description);
// Trac wiki headings: `= H1 =`, `== H2 ==`, ..., `===== H5 =====`. Trailing
// `=`s are optional. Map count → markdown `#`s.
//
// Split on fence markers so the heading regex never rewrites lines like
// `== separator ==` that appear inside a code block. Segments at odd indexes
// are inside a fenced block and are left untouched.
$convert_wiki = function (string $text): string {
    // Trac wiki headings: `= H1 =`, `== H2 ==`, ..., `===== H5 =====`.
    // Allow leading whitespace — Trac renders indented `== Heading ==` as a
    // heading too, and indented headings appear in descriptions that were
    // pasted from outline-style notes. Use [ \t] rather than \s to keep the
    // match per-line; \s would eat \n and glue the heading to the next line.
    $text = preg_replace_callback(
        '/^[ \t]*(={1,5})[ \t]+(.+?)[ \t]*=*[ \t]*\r?$/m',
        function ($m) {
            return str_repeat('#', strlen($m[1])) . ' ' . $m[2];
        },
        $text
    );
    // Trac [[Image(src, ...options)]] macro → markdown image. We keep only the
    // first argument (the source) and discard size/alt options; the source is
    // either an attachment filename or a URL, both of which Markdown handles.
    $text = preg_replace_callback(
        '/\[\[Image\(([^,)]+)(?:,[^)]*)?\)\]\]/i',
        function ($m) {
            return '![](' . trim($m[1]) . ')';
        },
        $text
    );
    // Trac italic markup: ''text'' → *text*. Use a lazy match so a paragraph
    // with multiple italic runs doesn't collapse into a single span. The
    // pattern requires non-empty content and refuses to start/end on a quote
    // so it doesn't eat real apostrophes.
    $text = preg_replace(
        "/''((?:[^']|'(?!'))+?)''/",
        '*$1*',
        $text
    );
    return $text;
};
$segments = preg_split('/(^```[^\n]*\n)/m', $description, -1, PREG_SPLIT_DELIM_CAPTURE);
$in_fence = false;
foreach ($segments as $i => $seg) {
    if (preg_match('/^```/', $seg)) {
        $in_fence = !$in_fence;
        continue;
    }
    if (!$in_fence) {
        $segments[$i] = $convert_wiki($seg);
    }
}
$description = implode('', $segments);

// ---- Render metadata header ----
echo "# Trac Ticket #{$ticket['id']}\n\n";

// Order: identity, people, classification, versioning, tags. CC excluded by design.
$field_order = [
    'component', 'summary',
    'reporter', 'owner',
    'type', 'status', 'resolution', 'priority', 'severity',
    'version', 'milestone',
    'keywords', 'focuses',
];
foreach ($field_order as $f) {
    $v = $ticket[$f] ?? '';
    if ($v === '' || $v === null) continue;
    $label = ucfirst($f);
    echo "**{$label}:** {$v}\n";
}
echo "\n## Description\n\n{$description}\n";

if ($short) {
    exit(0);
}

// ---- Parse RSS items into typed events ----
if ($rss_code < 200 || $rss_code >= 300) {
    fwrite(STDERR, "Error: Could not fetch ticket #{$ticket_num} discussion (HTTP {$rss_code})\n");
    exit(1);
}
$xml = simplexml_load_string($rss_body);
if ($xml === false || !isset($xml->channel)) {
    fwrite(STDERR, "Error: response for ticket #{$ticket_num} is not RSS — likely auth required (no cookie at \$TRAC_COOKIE_FILE, \$XDG_CONFIG_HOME/wp-trac/cookie, or ~/.config/wp-trac/cookie)\n");
    exit(1);
}

$namespaces = $xml->getNamespaces(true);
$dc_ns = $namespaces['dc'] ?? 'http://purl.org/dc/elements/1.1/';

$attachments = [];
$changesets  = [];
$comments    = [];

foreach ($xml->channel->item as $item) {
    $dc = $item->children($dc_ns);
    $author  = (string)$dc->creator;
    $title   = (string)$item->title;
    $link    = (string)$item->link;
    $pubDate = (string)$item->pubDate;
    $rawDesc = (string)$item->description;

    if ($author === 'slackbot') {
        continue;
    }

    // Pre-pass: strip a leading Trac field-change <ul> from the raw HTML
    // without DOM-parsing first, so that <pre> blocks containing literal PHP
    // open/close tags survive intact for convertXHTMLToMarkdown's own
    // pre-aware pipeline.
    //
    // Trac's field-change markup is structurally rigid:
    //   <ul>
    //     <li><strong>field</strong>: oldval → newval</li>
    //     ...
    //   </ul>
    // where `field` is one of a fixed set of ticket fields. Wiki bullet lists
    // can also start with <strong> (bold labels), so matching the structure
    // alone is not enough — we additionally require every <li>'s field name
    // to be a known Trac field, and the <ul> body to contain only such items.
    //
    // This runs BEFORE the prbot and changeset checks because a commit-close
    // or PR-opening RSS item can emit a field-change <ul> followed by the
    // narrative payload in the same item; the regexes that detect those
    // narratives are anchored at the start of the raw description.
    $known_fields = [
        'cc', 'component', 'description', 'focuses', 'keywords', 'milestone',
        'owner', 'priority', 'reporter', 'resolution', 'severity', 'status',
        'summary', 'type', 'version',
    ];
    $field_changes = [];

    if (preg_match('~^\s*<ul(?:\s[^>]*)?>(.*?)</ul>\s*~is', $rawDesc, $um)) {
        $ul_body  = $um[1];
        $li_re    = '~<li(?:\s[^>]*)?>\s*<strong>([^<]+)</strong>(.*?)</li>~is';
        $count    = preg_match_all($li_re, $ul_body, $lis, PREG_SET_ORDER);
        $residual = trim(preg_replace($li_re, '', $ul_body));
        if ($count > 0 && $residual === '') {
            $all_known = true;
            foreach ($lis as $m) {
                $field = strtolower(trim($m[1]));
                if (!in_array($field, $known_fields, true)) {
                    $all_known = false;
                    break;
                }
            }
            if ($all_known) {
                foreach ($lis as $m) {
                    $field = strtolower(trim($m[1]));
                    // CC is excluded by design from metadata; do the same here
                    // so CC churn doesn't surface in Discussion/Changesets.
                    // Keyword-bot churn is excluded for the same reason.
                    if ($field === 'keywords' || $field === 'cc') continue;
                    $entry = preg_replace('/<[^>]+>/', '', $m[0]);
                    $entry = html_entity_decode(
                        $entry, ENT_QUOTES | ENT_HTML5, 'UTF-8'
                    );
                    $field_changes[$field] = preg_replace(
                        '/\s+/', ' ', trim($entry)
                    );
                }
                $rawDesc = substr($rawDesc, strlen($um[0]));
            }
        }
    }

    // prbot relays GitHub PR activity into the Trac ticket as comments. We
    // handle two shapes; everything else is pure scaffolding and dropped:
    //
    //   A) PR-opening announcement:
    //        <p><em>This ticket was mentioned in <a>PR #N</a> on <a>repo</a>
    //          by <a>@user</a>.</em>
    //          <PR description body>
    //        </p>
    //      The body carries the PR description, which is NOT in the
    //      api.wordpress.org PR endpoint. Strip the <em>preamble</em>,
    //      re-attribute to the real GitHub user, keep the body.
    //
    //   B) Comment forwarded:
    //        <p><a>@user</a> commented on <a>PR #N</a>:</p>
    //        <p><comment body></p>
    //      Strip the first <p>…</p> preamble, re-attribute, keep the body.
    if ($author === 'prbot') {
        if (preg_match(
            '~^\s*<p>\s*<em>\s*This ticket was mentioned in\s+<a[^>]*>(?:<span[^>]*>[^<]*</span>\s*)?PR\s+\#\d+</a>(?:\s+on\s+<a[^>]*>(?:<span[^>]*>[^<]*</span>\s*)?[^<]+</a>)?\s+by\s+<a[^>]*>(?:<span[^>]*>[^<]*</span>\s*)?@([\w.-]+)</a>\s*\.?\s*</em>\s*~i',
            $rawDesc,
            $pm
        )) {
            $author  = $pm[1];
            // The body was inside the same <p> as the <em> preamble. Replace
            // the matched preamble (which consumed the opening <p>) with a
            // fresh <p> so the body sits in a well-formed paragraph; the
            // original closing </p> further down then balances it.
            $rawDesc = '<p>' . substr($rawDesc, strlen($pm[0]));
        } elseif (preg_match(
            '~^\s*<p>\s*<a[^>]*>[^@]*@([\w.-]+)</a>\s+commented on\s+<a[^>]*>(?:<span[^>]*>[^<]*</span>\s*)?PR\s+\#\d+</a>\s*:?\s*</p>\s*~i',
            $rawDesc,
            $pm
        )) {
            $author  = $pm[1];
            $rawDesc = substr($rawDesc, strlen($pm[0]));
        } else {
            continue;
        }
    }

    $date = $pubDate ? date('Y-m-d', strtotime($pubDate)) : '';

    $cnum = '';
    if (preg_match('/#(comment:\d+|description)/', $link, $m)) {
        $cnum = $m[1];
    }

    // The RSS feed emits an item for description edits whose body re-renders
    // the current description. The TSV-driven Description section already
    // shows that content, so emitting it again as a comment is pure noise.
    if ($cnum === 'description') {
        continue;
    }

    // Attachment item
    if ($title === 'attachment set') {
        $filename = '';
        if (preg_match('#<em>([^<]+)</em>#', $rawDesc, $am)) {
            $filename = html_entity_decode($am[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $extra = '';
        if (preg_match('#</ul>\s*(.+)$#is', $rawDesc, $em)) {
            $extra = trim(convertXHTMLToMarkdown($em[1]));
        }
        // Encode the filename path segment so spaces and reserved characters
        // don't break the URL when copy-pasted out of markdown.
        $att_url = $filename
            ? "https://core.trac.wordpress.org/raw-attachment/ticket/{$ticket_num}/" . rawurlencode($filename)
            : '';
        $attachments[] = [
            'filename' => $filename,
            'author'   => $author,
            'date'     => $date,
            'url'      => $att_url,
            'extra'    => $extra,
        ];
        continue;
    }

    // Changeset auto-comment: anchored "In <a class=changeset>[N]</a>:" at the
    // start of the (post-field-change-strip) description. Anchoring avoids
    // misclassifying regular comments that happen to mention a changeset link
    // inline (e.g. "fixed in [59369]" inside prose).
    if (preg_match(
        '#^\s*<p>\s*In\s*<a\s+class="changeset"\s+href="([^"]+)"[^>]*>\[?(\d+)\]?</a>\s*:?\s*</p>\s*#i',
        $rawDesc,
        $cm
    )) {
        $cs_href = $cm[1];
        $cs_num  = $cm[2];
        if (str_starts_with($cs_href, '/')) {
            $cs_href = "https://core.trac.wordpress.org{$cs_href}";
        }
        $rest = substr($rawDesc, strlen($cm[0]));
        $msg = trim(convertXHTMLToMarkdown($rest));
        $changesets[] = [
            'number'  => $cs_num,
            'url'     => $cs_href,
            'author'  => $author,
            'date'    => $date,
            'msg'     => $msg,
            'changes' => $field_changes,
        ];
        continue;
    }

    $body_md = trim(convertXHTMLToMarkdown($rawDesc));

    if ($field_changes === [] && $body_md === '') {
        continue;
    }

    $comments[] = [
        'author'  => $author,
        'date'    => $date,
        'cnum'    => $cnum,
        'changes' => $field_changes,
        'body'    => $body_md,
    ];
}

// ---- Render Attachments ----
if ($attachments !== []) {
    echo "\n## Attachments\n\n";
    foreach ($attachments as $a) {
        $name = $a['filename'] !== '' ? $a['filename'] : '(unnamed)';
        echo "- **{$name}** — uploaded by {$a['author']} on {$a['date']}";
        if ($a['url'] !== '') echo " — {$a['url']}";
        echo "\n";
        if ($a['extra'] !== '') {
            foreach (preg_split('/\n/', $a['extra']) as $line) {
                echo "  {$line}\n";
            }
        }
    }
}

// ---- Render Changesets ----
if ($changesets !== []) {
    echo "\n## Changesets\n\n";
    foreach ($changesets as $c) {
        echo "### [{$c['number']}] by {$c['author']} on {$c['date']}\n\n";
        echo "{$c['url']}\n\n";
        if (!empty($c['changes'])) {
            echo "_" . implode('; ', array_values($c['changes'])) . "_\n\n";
        }
        if ($c['msg'] !== '') {
            echo "{$c['msg']}\n\n";
        }
    }
}

// ---- Render Discussion ----
echo "\n## Discussion\n\n";
if ($comments === []) {
    echo "_No comments found._\n";
} else {
    foreach ($comments as $c) {
        $head = "### {$c['author']}";
        if ($c['cnum'] !== '') $head .= " ({$c['cnum']})";
        if ($c['date'] !== '') $head .= " — {$c['date']}";
        echo "{$head}\n\n";
        if ($c['changes'] !== []) {
            $parts = array_values($c['changes']);
            echo "_" . implode('; ', $parts) . "_\n\n";
        }
        if ($c['body'] !== '') {
            echo "{$c['body']}\n\n";
        }
    }
}

// ---- Render Pull Requests ----
echo "## Pull Requests\n\n";
// A failed PR fetch means the output is incomplete. Exit non-zero so callers
// that record the output (e.g. the eval harness) treat this as a real
// failure rather than caching a half-rendered ticket as a successful run.
if ($pr_code < 200 || $pr_code >= 300) {
    fwrite(STDERR, "Error: Could not fetch pull requests for #{$ticket_num} (HTTP {$pr_code})\n");
    echo "_Could not fetch pull requests (HTTP {$pr_code})._\n";
    exit(1);
}
$prs = json_decode($pr_body, true);
if (!is_array($prs)) {
    fwrite(STDERR, "Error: Could not parse pull request response for #{$ticket_num}\n");
    echo "_Could not parse pull request response._\n";
    exit(1);
}
if (count($prs) === 0) {
    echo "_No pull requests found._\n";
    exit(0);
}
usort($prs, function ($a, $b) {
    $aOpen = (($a['state'] ?? '') === 'open') ? 0 : 1;
    $bOpen = (($b['state'] ?? '') === 'open') ? 0 : 1;
    if ($aOpen !== $bOpen) return $aOpen - $bOpen;
    return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
});
foreach ($prs as $pr) {
    $number    = $pr['number']       ?? '—';
    $ptitle    = $pr['title']        ?? '—';
    $state     = $pr['state']        ?? '—';
    $pauthor   = $pr['user']['name'] ?? '—';
    $purl      = $pr['html_url']     ?? '—';
    $created   = substr($pr['created_at'] ?? '', 0, 10) ?: '—';
    $updated   = substr($pr['updated_at'] ?? '', 0, 10) ?: '—';
    $closedRaw = $pr['closed_at'] ?? null;
    $additions = $pr['changes']['additions'] ?? '—';
    $deletions = $pr['changes']['deletions'] ?? '—';
    $touches   = !empty($pr['touches_tests']) ? 'yes' : 'no';

    echo "### #{$number} — {$ptitle}\n\n";
    echo "- **State:** {$state}\n";
    echo "- **Author:** {$pauthor}\n";
    echo "- **URL:** {$purl}\n";
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
