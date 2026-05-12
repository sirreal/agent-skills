<?php
/**
 * Convert XHTML string from Trac RSS to markdown.
 *
 * Uses Dom\HTMLDocument (PHP 8.4+) so interleaved text nodes between
 * element children are preserved — SimpleXML hides text nodes, which
 * caused widespread silent content loss in comment bodies.
 *
 * <pre> bodies are snapshotted via regex before HTML parsing because
 * HTML5 turns literal <?php ... ?> inside <pre> into comment nodes and
 * <input>/<script> into real elements, which loses code-block content.
 */

function convertXHTMLToMarkdown(string $html): string {
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $preBlocks = [];
    $html = preg_replace_callback(
        '#<pre\b([^>]*)>(.*?)</pre>#is',
        function ($m) use (&$preBlocks) {
            $idx = count($preBlocks);
            $preBlocks[] = $m[2];
            return "<pre{$m[1]}>__WP_TRAC_PRE_{$idx}__</pre>";
        },
        $html
    );

    $wrapped = "<div>{$html}</div>";
    $doc = Dom\HTMLDocument::createFromString($wrapped, LIBXML_HTML_NOIMPLIED);
    $root = $doc->getElementsByTagName('div')->item(0);

    $out = convertDomNode($root, $preBlocks);
    return preg_replace('/\n{3,}/', "\n\n", trim($out, " \t\n\r\f"));
}

function convertDomNode(Dom\Node $node, array $preBlocks): string {
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
                $result .= "\n\n" . convertDomNode($child, $preBlocks) . "\n\n";
                break;
            case 'code':
                $result .= '`' . $child->textContent . '`';
                break;
            case 'pre':
                $class = $child->getAttribute('class');
                $lang = '';
                if ($class !== '' && preg_match('/\bwiki-code-(\w+)\b/', $class, $matches)) {
                    $lang = $matches[1];
                }
                $placeholder = $child->textContent;
                if (preg_match('/__WP_TRAC_PRE_(\d+)__/', $placeholder, $pm)
                    && isset($preBlocks[(int)$pm[1]])
                ) {
                    $raw = $preBlocks[(int)$pm[1]];
                    $raw = preg_replace('#<br\s*/?>#i', "\n", $raw);
                    $raw = preg_replace('#<(a|span)\b[^>]*>(.*?)</\1>#is', '$2', $raw);
                    $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                } else {
                    $raw = $child->textContent;
                }
                $result .= "\n\n```{$lang}\n" . trim($raw) . "\n```\n\n";
                break;
            case 'a':
                $href = $child->getAttribute('href');
                $text = convertDomNode($child, $preBlocks);
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
                $result .= '**' . convertDomNode($child, $preBlocks) . '**';
                break;
            case 'em':
            case 'i':
                $result .= '_' . convertDomNode($child, $preBlocks) . '_';
                break;
            case 'ul':
            case 'ol':
                $result .= "\n" . convertDomNode($child, $preBlocks) . "\n";
                break;
            case 'li':
                $result .= '- ' . trim(convertDomNode($child, $preBlocks)) . "\n";
                break;
            case 'blockquote':
                $inner = trim(convertDomNode($child, $preBlocks));
                $quoted = preg_replace('/^/m', '> ', $inner);
                $result .= "\n" . $quoted . "\n";
                break;
            default:
                $result .= convertDomNode($child, $preBlocks);
                break;
        }
    }

    return $result;
}
