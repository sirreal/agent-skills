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
        LIBXML_HTML_NOIMPLIED | LIBXML_NOERROR
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
