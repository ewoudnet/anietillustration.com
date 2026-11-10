<?php

declare(strict_types=1);

namespace App;

/**
 * Allow-list sanitizer voor de opgemaakte special-tekst (editor in de backend). Alles wat
 * niet expliciet is toegestaan wordt verwijderd (tags uitgepakt, scripts/styles volledig,
 * alle attributen behalve veilige link-href). Wordt zowel bij opslaan als bij tonen
 * toegepast.
 */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'h3', 'h4', 'a', 'blockquote'];
    private const TAG_ALIASES = ['b' => 'strong', 'i' => 'em', 'h1' => 'h3', 'h2' => 'h3', 'h5' => 'h4', 'h6' => 'h4', 'div' => 'p'];
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'form'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="sanitize-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('sanitize-root');
        if ($root === null) {
            return h($html);
        }

        self::sanitizeChildren($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Voor weergave: oude platte-tekstbeschrijvingen (zonder tags) behouden hun regeleinden,
     * opgemaakte tekst wordt (opnieuw) opgeschoond.
     */
    public static function render(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        return preg_match('/<\/?[a-z][^>]*>/i', $text) === 1 ? self::clean($text) : nl2br(h($text));
    }

    /**
     * True als er na opschonen geen zichtbare inhoud overblijft (lege editor = <p><br></p>).
     */
    public static function isEmpty(string $html): bool
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) === '';
    }

    private static function sanitizeChildren(\DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof \DOMComment || $node instanceof \DOMProcessingInstruction) {
                $parent->removeChild($node);
                continue;
            }
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $name = strtolower($node->nodeName);

            if (in_array($name, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($node);
                continue;
            }

            self::sanitizeChildren($node);

            $name = self::TAG_ALIASES[$name] ?? $name;

            if (!in_array($name, self::ALLOWED_TAGS, true)) {
                // Onbekende tag: inhoud behouden, tag zelf weg.
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            if ($name !== strtolower($node->nodeName)) {
                $replacement = $node->ownerDocument->createElement($name);
                while ($node->firstChild !== null) {
                    $replacement->appendChild($node->firstChild);
                }
                $parent->replaceChild($replacement, $node);
                $node = $replacement;
            }

            $href = $name === 'a' ? trim((string) $node->getAttribute('href')) : '';
            while ($node->attributes->length > 0) {
                $node->removeAttributeNode($node->attributes->item(0));
            }

            if ($name === 'a') {
                if (preg_match('#^(https?://|mailto:)#i', $href)) {
                    $node->setAttribute('href', $href);
                    $node->setAttribute('target', '_blank');
                    $node->setAttribute('rel', 'noopener noreferrer');
                } else {
                    while ($node->firstChild !== null) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }
            }
        }
    }
}
