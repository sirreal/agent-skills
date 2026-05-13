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

    $pr_ch = curl_init($pr_url);
    curl_setopt($pr_ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($pr_ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($pr_ch, CURLOPT_USERAGENT, 'wp-trac-ticket/2.0');
    trac_apply_cookie($pr_ch);
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
$description = preg_replace_callback(
    '/^(={1,5})\s+(.+?)\s*=*\s*$/m',
    function ($m) {
        return str_repeat('#', strlen($m[1])) . ' ' . $m[2];
    },
    $description
);

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

    // prbot has two shapes:
    //   1) Pure scaffolding ("This ticket was mentioned in PR #N…") — drop;
    //      duplicates content already in the PR endpoint.
    //   2) Forwarded GitHub PR discussion ("@user commented on PR #N: …prose")
    //      — substantive content unavailable elsewhere. Re-attribute to the
    //      real GitHub user and strip the forwarding preamble.
    if ($author === 'prbot') {
        if (preg_match(
            '~^\s*<p>\s*<a[^>]*>[^@]*@([\w-]+)</a>\s+commented on\s+<a[^>]*>(?:<span[^>]*>[^<]*</span>\s*)?PR\s+\#\d+</a>\s*:?\s*</p>\s*~i',
            $rawDesc,
            $pm
        )) {
            $author  = $pm[1];
            $rawDesc = substr($rawDesc, strlen($pm[0]));
            // fall through to normal comment processing
        } else {
            continue;
        }
    }

    $date = $pubDate ? date('Y-m-d', strtotime($pubDate)) : '';

    $cnum = '';
    if (preg_match('/#(comment:\d+|description)/', $link, $m)) {
        $cnum = $m[1];
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
        $att_url = $filename
            ? "https://core.trac.wordpress.org/raw-attachment/ticket/{$ticket_num}/{$filename}"
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

    // Changeset auto-comment: anchored "In <a class=changeset>[N]</a>:" at start.
    // Anchoring avoids misclassifying regular comments that happen to mention a
    // changeset link inline (e.g. "fixed in [59369]" inside prose).
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
            'number' => $cs_num,
            'url'    => $cs_href,
            'author' => $author,
            'date'   => $date,
            'msg'    => $msg,
        ];
        continue;
    }

    // Field-change OR comment: split first <ul> (change list) from body.
    $doc = @Dom\HTMLDocument::createFromString("<div>{$rawDesc}</div>", LIBXML_HTML_NOIMPLIED);
    $root = $doc?->getElementsByTagName('div')->item(0);

    $field_changes = [];
    $body_html = '';

    if ($root !== null) {
        $first_ul_consumed = false;
        foreach ($root->childNodes as $child) {
            if (!$first_ul_consumed
                && $child->nodeType === XML_ELEMENT_NODE
                && strtolower($child->localName) === 'ul'
                && $title !== ''
            ) {
                foreach ($child->childNodes as $li) {
                    if ($li->nodeType !== XML_ELEMENT_NODE) continue;
                    if (strtolower($li->localName) !== 'li') continue;
                    $strong_text = '';
                    foreach ($li->childNodes as $sc) {
                        if ($sc->nodeType === XML_ELEMENT_NODE
                            && strtolower($sc->localName) === 'strong'
                        ) {
                            $strong_text = strtolower(trim($sc->textContent));
                            break;
                        }
                    }
                    if ($strong_text === '') continue;
                    if ($strong_text === 'keywords') continue;
                    $field_changes[$strong_text] = preg_replace(
                        '/\s+/', ' ', trim($li->textContent)
                    );
                }
                $first_ul_consumed = true;
                continue;
            }
            if ($child->nodeType === XML_TEXT_NODE) {
                $body_html .= htmlspecialchars($child->textContent, ENT_QUOTES);
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $body_html .= $doc->saveHTML($child);
            }
        }
    }

    $body_md = trim(convertXHTMLToMarkdown($body_html));

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
if ($pr_code < 200 || $pr_code >= 300) {
    echo "_Could not fetch pull requests (HTTP {$pr_code})._\n";
    exit(0);
}
$prs = json_decode($pr_body, true);
if (!is_array($prs)) {
    echo "_Could not parse pull request response._\n";
    exit(0);
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
