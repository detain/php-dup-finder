<?php
declare(strict_types=1);

namespace Acme\Forum\Sanitize;

final class DomDocumentSanitizer
{
    /** @var array<string,list<string>> */
    private array $allowed;

    public function __construct()
    {
        $this->allowed = [
            'a'      => ['href', 'title'],
            'p'      => [],
            'strong' => [],
            'em'     => [],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
            'br'     => [],
            'code'   => [],
            'pre'    => [],
            'blockquote' => [],
        ];
    }

    public function clean(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//*');
        if ($nodes !== false) {
            $toRemove = [];
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $name = strtolower($node->nodeName);
                if (!isset($this->allowed[$name])) {
                    $toRemove[] = $node;
                    continue;
                }
                $attrs = $this->allowed[$name];
                foreach (iterator_to_array($node->attributes ?? []) as $attr) {
                    if (!in_array(strtolower($attr->nodeName), $attrs, true)) {
                        $node->removeAttribute($attr->nodeName);
                    }
                    if ($attr->nodeName === 'href' && !preg_match('#^(https?://|/|mailto:)#i', $attr->nodeValue ?? '')) {
                        $node->removeAttribute('href');
                    }
                }
            }
            foreach ($toRemove as $node) {
                while ($node->firstChild) {
                    $node->parentNode->insertBefore($node->firstChild, $node);
                }
                $node->parentNode->removeChild($node);
            }
        }
        $out = $doc->saveHTML($doc->documentElement) ?: '';
        return trim(preg_replace('#^<div>|</div>$#', '', $out) ?? $out);
    }
}
