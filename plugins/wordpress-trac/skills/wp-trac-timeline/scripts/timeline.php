#!/usr/bin/env php
<?php
/**
 * Fetch WordPress Trac timeline as markdown.
 *
 * Usage: timeline.php [options]
 */

// Check for required curl extension
if (!extension_loaded('curl')) {
    fwrite(STDERR, "Error: This script requires the curl extension.\n");
    fwrite(STDERR, "Please install or enable the curl extension for PHP.\n");
    exit(1);
}

$help = <<<'HELP'
timeline.php [options]

Options:
  --from=YYYY-MM-DD   End date for timeline (default: today)
  --daysback=N        Number of days to look back (1-90, default: 30)
  --author=USER       Filter by author (repeat for multiple)
                      Omit for all authors
  --help              Show this help

Examples:
  timeline.php --author=jonsurrell --daysback=14
  timeline.php --from=2023-01-15 --daysback=7 --author=saxmatt --author=ryan
  timeline.php --daysback=30
HELP;

$longopts = [
    'from:',
    'daysback:',
    'author:',
    'help',
];

$options = getopt('', $longopts);

if (isset($options['help'])) {
    echo $help . "\n";
    exit(0);
}

// Build query parameters
$from = $options['from'] ?? date('Y-m-d');
$daysback = $options['daysback'] ?? 30;

// Handle multiple --author options (space encodes to + in query string)
$authors = '';
if (isset($options['author'])) {
    $author_list = (array) $options['author'];
    $authors = implode(' ', $author_list);
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    fwrite(STDERR, "Error: Invalid date format. Use YYYY-MM-DD\n");
    exit(1);
}

// Validate daysback is numeric and within range
if (!ctype_digit((string) $daysback) || $daysback < 1 || $daysback > 90) {
    fwrite(STDERR, "Error: daysback must be between 1 and 90\n");
    exit(1);
}

// Build URL with static parameters
$params = [
    'from' => $from,
    'daysback' => $daysback,
    'authors' => $authors,
    'ticket' => 'on',
    'ticket_details' => 'on',
    'repo-' => 'on',
    'format' => 'rss',
];

$url = 'https://core.trac.wordpress.org/timeline?' . http_build_query($params);

// Fetch RSS feed
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'wp-trac-timeline/1.0');
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

if ($http_code < 200 || $http_code >= 300) {
    fwrite(STDERR, "Error: Could not fetch timeline (HTTP {$http_code})\n");
    exit(1);
}

// Parse RSS
libxml_use_internal_errors(true);
$rss = simplexml_load_string($response);

if ($rss === false) {
    fwrite(STDERR, "Error: Invalid RSS response\n");
    exit(1);
}

// Map category to human-readable type
function get_event_type(string $category): string {
    return match ($category) {
        'changeset' => 'Changeset',
        'newticket' => 'Ticket Created',
        'editedticket' => 'Ticket Updated',
        'closedticket' => 'Ticket Closed',
        default => ucfirst($category),
    };
}

// Clean HTML description to markdown
function clean_description(string $html): string {
    // Decode HTML entities
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Remove HTML tags but preserve line breaks
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = strip_tags($text);

    // Collapse multiple newlines
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

// Extract items
$items = $rss->channel->item ?? [];

if (count($items) === 0) {
    echo "No activity found for the specified period.\n";
    exit(0);
}

// Output header
$author_display = $authors ?: 'all authors';
echo "# WordPress Trac Timeline\n\n";
echo "**Period:** {$daysback} days ending {$from}\n";
echo "**Authors:** {$author_display}\n";
echo "**Results:** " . count($items) . " items\n\n";
echo "---\n\n";

// Output items (already in reverse chronological order from RSS)
$total = count($items);
$i = 0;
foreach ($items as $item) {
    $i++;
    $title = (string) $item->title;
    $link = (string) $item->link;
    $pubDate = (string) $item->pubDate;
    $creator = (string) $item->children('http://purl.org/dc/elements/1.1/')->creator;
    $description = (string) $item->description;
    $category = (string) $item->category;

    // Format date
    $date = date('Y-m-d H:i', strtotime($pubDate));

    // Get event type
    $type = get_event_type($category);

    // Clean title (remove trailing whitespace and truncation)
    $title = trim($title);

    // Output
    echo "## [{$type}] {$title}\n\n";
    echo "**Author:** {$creator} | **Date:** {$date}\n";
    echo "**Link:** {$link}\n\n";

    $desc = clean_description($description);
    if ($desc && $desc !== $title) {
        echo "{$desc}\n\n";
    }

    if ($i < $total) {
        echo "---\n\n";
    }
}
