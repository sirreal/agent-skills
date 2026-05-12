#!/usr/bin/env php
<?php
/**
 * Fetch WordPress Trac ticket info as markdown.
 *
 * Usage: ticket.php [--discussion | --prs] <ticket-number>
 */

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

require_once __DIR__ . '/html-to-markdown.php';

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

// Validate ticket number is numeric
if (!ctype_digit($ticket_num)) {
    fwrite(STDERR, "Error: Invalid ticket number: {$ticket_num}\n");
    exit(1);
}

// Discussion mode: fetch RSS and parse comments
if ($mode === 'discussion') {
    $url = "https://core.trac.wordpress.org/ticket/{$ticket_num}?format=rss";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'wp-trac-ticket/1.0');
    trac_apply_cookie($ch);
    $rss = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($http_code < 200 || $http_code >= 300) {
        fwrite(STDERR, "Error: Could not fetch ticket #{$ticket_num} discussion (HTTP {$http_code})\n");
        exit(1);
    }

    $xml = simplexml_load_string($rss);
    if ($xml === false) {
        fwrite(STDERR, "Error: Could not parse RSS for ticket #{$ticket_num}\n");
        exit(1);
    }

    // Real RSS has a <channel> under <rss>. The Trac auth challenge page
    // parses as XML but lacks <channel>, which previously silently became
    // "_No comments found._" — masking a missing cookie as an empty thread.
    if (!isset($xml->channel)) {
        fwrite(STDERR, "Error: response for ticket #{$ticket_num} is not RSS — likely auth required (no cookie at \$TRAC_COOKIE_FILE, \$XDG_CONFIG_HOME/wp-trac/cookie, or ~/.config/wp-trac/cookie)\n");
        exit(1);
    }

    // Register dc namespace for creator
    $namespaces = $xml->getNamespaces(true);

    echo "# Trac Ticket #{$ticket_num} Discussion\n\n";

    $items = $xml->channel->item;
    if (count($items) === 0) {
        echo "_No comments found._\n";
    } else {
        foreach ($items as $item) {
            // Get dc:creator
            $dc = $item->children($namespaces['dc'] ?? 'http://purl.org/dc/elements/1.1/');
            $author = (string)$dc->creator;

            // Skip attachment items (title = "attachment set").
            if ((string)$item->title === 'attachment set') {
                continue;
            }

            // Skip prbot comments — PR content is available via --prs.
            if ($author === 'prbot') {
                continue;
            }

            // Extract comment number from link
            $link = (string)$item->link;
            $comment_num = '';
            if (preg_match('/#(comment:\d+|description)/', $link, $matches)) {
                $comment_num = $matches[1];
            }

            // Get description and convert HTML to markdown
            $description = (string)$item->description;
            $description = convertXHTMLToMarkdown($description);

            // Skip if no meaningful content
            if (empty(trim($description))) {
                continue;
            }

            echo "## {$author} ({$comment_num})\n\n";
            echo "{$description}\n\n";
        }
    }

    exit(0);
}

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
$stream = fopen('php://temp', 'r+');

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_FILE, $stream);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'wp-trac-ticket/1.0');
trac_apply_cookie($ch);
curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

if ($http_code < 200 || $http_code >= 300) {
    fwrite(STDERR, "Error: Could not fetch ticket #{$ticket_num}\n");
    exit(1);
}

// Parse TSV using fgetcsv which handles multiline quoted fields
rewind($stream);
$headers = fgetcsv($stream, 0, "\t", '"', '');
$values = fgetcsv($stream, 0, "\t", '"', '');
fclose($stream);

if ($headers === false || $values === false) {
    fwrite(STDERR, "Error: Invalid response for ticket #{$ticket_num}\n");
    exit(1);
}

// Strip BOM from first header if present
$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);

if (count($headers) !== count($values)) {
    fwrite(STDERR, "Error: Malformed TSV data for ticket #{$ticket_num}\n");
    exit(1);
}

// Create associative array
$ticket = array_combine($headers, $values);

// Convert Trac wiki syntax to markdown
$description = $ticket['description'];
// Convert code fence openers: {{{#!lang -> ```lang (xml becomes html)
$description = preg_replace_callback(
    '/^\{\{\{(?:#!(\w+))?\r?$/m',
    function ($matches) {
        $lang = $matches[1] ?? '';
        if ($lang === 'xml') {
            $lang = 'html';
        }
        return '```' . $lang;
    },
    $description
);
// Convert code fence closers: }}} -> ```
$description = preg_replace('/^\}\}\}\r?$/m', '```', $description);

// Output as markdown
echo "# Trac Ticket #{$ticket['id']}\n";
echo "\n";
echo "**Component:** {$ticket['component']}\n";
echo "**Summary:** {$ticket['summary']}\n";
echo "**Type:** {$ticket['type']}\n";
echo "**Status:** {$ticket['status']}\n";
echo "**Milestone:** {$ticket['milestone']}\n";
echo "\n";
echo "## Description\n";
echo "\n";
echo "{$description}\n";
