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
