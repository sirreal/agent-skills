#!/usr/bin/env php
<?php
/**
 * Fetch WordPress Trac ticket info as markdown.
 *
 * Usage: ticket.php [--discussion] <ticket-number>
 */

// Check for required curl extension
if (!extension_loaded('curl')) {
    fwrite(STDERR, "Error: This script requires the curl extension.\n");
    fwrite(STDERR, "Please install or enable the curl extension for PHP.\n");
    exit(1);
}

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

// Validate ticket number is numeric
if (!ctype_digit($ticket_num)) {
    fwrite(STDERR, "Error: Invalid ticket number: {$ticket_num}\n");
    exit(1);
}

// Discussion mode: fetch RSS and parse comments
if ($discussion_mode) {
    $url = "https://core.trac.wordpress.org/ticket/{$ticket_num}?format=rss";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'wp-trac-ticket/1.0');
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

            // Extract comment number from link
            $link = (string)$item->link;
            $comment_num = '';
            if (preg_match('/#(comment:\d+|description)/', $link, $matches)) {
                $comment_num = $matches[1];
            }

            // Get description and convert HTML to markdown
            $description = html_entity_decode((string)$item->description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

/**
 * Convert XHTML string to markdown using SimpleXML.
 */
function convertXHTMLToMarkdown(string $html): string {
    $html = trim($html);
    if (empty($html)) {
        return '';
    }

    // Wrap in root element and parse as XML
    $xml = @simplexml_load_string("<root>{$html}</root>", 'SimpleXMLElement', LIBXML_NOERROR);
    if ($xml === false) {
        return strip_tags($html);
    }

    return convertXMLNode($xml);
}

/**
 * Recursively convert SimpleXML node to markdown.
 */
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

    // Handle text nodes at this level (mixed content)
    // SimpleXML doesn't expose text nodes directly, so we check if there's direct text
    $directText = trim((string)$node);
    if (!empty($directText) && $node->count() === 0) {
        $result .= $directText;
    }

    // Clean up excessive whitespace
    return preg_replace('/\n{3,}/', "\n\n", trim($result, " \t\n\r\f"));
}

// Fetch ticket data in TSV format, streaming directly to a temp file
$url = "https://core.trac.wordpress.org/ticket/{$ticket_num}?format=tab";
$stream = fopen('php://temp', 'r+');

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_FILE, $stream);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'wp-trac-ticket/1.0');
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
