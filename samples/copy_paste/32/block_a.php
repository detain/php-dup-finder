<?php

declare(strict_types=1);

namespace App\Sanitization;

use App\Exceptions\HtmlStripException;

final class HtmlStripper
{
    private const BLOCK_TAGS = [
        'address', 'article', 'aside', 'blockquote', 'center', 'dd', 'details',
        'dir', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer',
        'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr',
        'li', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'section', 'summary',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    private const INLINE_TAGS = [
        'a', 'abbr', 'acronym', 'b', 'bdo', 'big', 'br', 'cite', 'code', 'dfn',
        'em', 'font', 'i', 'img', 'input', 'kbd', 'label', 'mark', 'meter', 'output',
        'progress', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'select', 'small', 'span',
        'strike', 'strong', 'sub', 'sup', 'textarea', 'time', 'tt', 'u', 'var', 'wbr',
    ];

    private const FORBIDDEN_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form'];

    private const ALLOWED_TAGS = ['a', 'p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'blockquote'];

    public function stripAll(string $html): string
    {
        return strip_tags($html);
    }

    public function stripWithAllowed(string $html, array $allowedTags = self::ALLOWED_TAGS): string
    {
        if (empty($allowedTags)) {
            return $this->stripAll($html);
        }

        return strip_tags($html, '<' . implode('><', $allowedTags) . '>');
    }

    public function stripWithExclusions(string $html, array $forbiddenTags = self::FORBIDDEN_TAGS): string
    {
        $forbiddenPattern = $this->buildTagPattern($forbiddenTags);

        $html = preg_replace($forbiddenPattern, '', $html);
        $html = $this->stripEventAttributes($html);

        return $html;
    }

    public function stripComments(string $html): string
    {
        return preg_replace('/<!--[\s\S]*?-->/', '', $html);
    }

    public function stripScriptsAndStyles(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html);

        return $html;
    }

    public function stripLinks(string $html): string
    {
        return preg_replace('/<a\b[^>]*>.*?<\/a>/is', '', $html);
    }

    public function stripImages(string $html): string
    {
        return preg_replace('/<img\b[^>]*\/?>/is', '', $html);
    }

    public function stripForms(string $html): string
    {
        return preg_replace('/<form\b[^>]*>.*?<\/form>/is', '', $html);
    }

    public function stripEventAttributes(string $html): string
    {
        $eventAttributes = [
            'onclick', 'oncontextmenu', 'ondblclick', 'onmousedown', 'onmouseenter',
            'onmouseleave', 'onmousemove', 'onmouseover', 'onmouseout', 'onmouseup',
            'onkeydown', 'onkeypress', 'onkeyup', 'onabort', 'oncanplay',
            'oncanplaythrough', 'ondurationchange', 'onemptied', 'onended',
            'onerror', 'onload', 'onloadeddata', 'onloadedmetadata', 'onloadstart',
            'onpause', 'onplay', 'onplaying', 'onprogress', 'onratechange',
            'onreset', 'onseeked', 'onseeking', 'onstalled', 'onsubmit', 'onsuspend',
            'ontimeupdate', 'onvolumechange', 'onwaiting', 'onfocus', 'onblur',
        ];

        $pattern = '/\s*(' . implode('|', $eventAttributes) . ')\s*=\s*["\'][^"\']*["\']/i';

        return preg_replace($pattern, '', $html);
    }

    public function stripJavascriptUrls(string $html): string
    {
        $patterns = [
            '/href\s*=\s*["\']javascript:[^"\']*["\']/i',
            '/src\s*=\s*["\']javascript:[^"\']*["\']/i',
            '/action\s*=\s*["\']javascript:[^"\']*["\']/i',
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html);
        }

        return $html;
    }

    public function stripAllEventHandlers(string $html): string
    {
        $html = $this->stripEventAttributes($html);
        $html = $this->stripJavascriptUrls($html);
        $html = $this->stripScriptsAndStyles($html);

        return $html;
    }

    public function sanitizeForDisplay(string $html): string
    {
        $html = $this->stripComments($html);
        $html = $this->stripScriptsAndStyles($html);
        $html = $this->stripEventAttributes($html);
        $html = $this->stripJavascriptUrls($html);

        return trim($html);
    }

    public function extractText(string $html): string
    {
        $html = $this->stripComments($html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = strip_tags($html);

        return trim(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function buildTagPattern(array $tags): string
    {
        $escaped = array_map('preg_quote', $tags);

        return '/<\/?(' . implode('|', $escaped) . ')\b[^>]*>/i';
    }
}
