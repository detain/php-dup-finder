<?php

declare(strict_types=1);

namespace App\Helpers;

class HtmlSanitizer
{
    private static array $allowedTags = [
        'a', 'abbr', 'acronym', 'address', 'b', 'blockquote', 'br',
        'caption', 'cite', 'code', 'col', 'colgroup', 'dd', 'del',
        'dfn', 'div', 'dl', 'dt', 'em', 'fieldset', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'map',
        'ol', 'p', 'pre', 'q', 'samp', 'small', 'span', 'strong', 'sub',
        'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'tt',
        'ul', 'var',
    ];

    private static array $allowedAttributes = [
        'href', 'src', 'alt', 'title', 'class', 'id', 'style',
        'width', 'height', 'align', 'valign', 'bgcolor', 'border',
        'cellpadding', 'cellspacing', 'rel', 'target', 'name',
    ];

    public static function sanitize(string $html): string
    {
        // Remove all HTML tags first
        $html = strip_tags($html);

        // Then add back allowed tags
        $allowedTags = '<' . implode('><', self::$allowedTags) . '>';
        $html = strip_tags($html, $allowedTags);

        // Sanitize attributes
        $html = self::sanitizeAttributes($html);

        // Remove JavaScript protocol
        $html = preg_replace('/javascript\s*:/i', '', $html);

        // Remove on* event handlers
        $html = preg_replace('/\bon\w+\s*=\s*[^>]*>/i', '>', $html);

        // Remove style attributes with expressions
        $html = preg_replace('/style\s*=\s*["\'][^"\']*expression\([^)]*\)[^"\']*["\']/i', '', $html);

        // Remove vbscript
        $html = preg_replace('/vbscript\s*:/i', '', $html);

        return $html;
    }

    public static function sanitizeAttributes(string $html): string
    {
        return preg_replace_callback(
            '/<(\w+)([^>]*)>/i',
            function ($matches) {
                $tag = $matches[1];
                $attributes = $matches[2];

                $sanitizedAttrs = [];

                if (preg_match_all('/(\w+)=["\']([^"\']*)["\']/', $attributes, $attrMatches)) {
                    foreach ($attrMatches[1] as $index => $attrName) {
                        $attrValue = $attrMatches[2][$index];

                        if (in_array(strtolower($attrName), self::$allowedAttributes)) {
                            // Sanitize attribute values
                            $attrValue = htmlspecialchars($attrValue, ENT_QUOTES, 'UTF-8');

                            // Check for dangerous values
                            if (self::isDangerousAttribute($attrName, $attrValue)) {
                                continue;
                            }

                            $sanitizedAttrs[] = $attrName . '="' . $attrValue . '"';
                        }
                    }
                }

                $attrString = !empty($sanitizedAttrs) ? ' ' . implode(' ', $sanitizedAttrs) : '';

                return '<' . $tag . $attrString . '>';
            },
            $html
        );
    }

    private static function isDangerousAttribute(string $name, string $value): bool
    {
        $name = strtolower($name);

        // Dangerous attribute names
        if (in_array($name, ['onclick', 'onload', 'onerror', 'onmouseover'])) {
            return true;
        }

        // Dangerous protocols in attributes
        $dangerousProtocols = ['javascript', 'vbscript', 'data'];

        foreach ($dangerousProtocols as $protocol) {
            if (stripos($value, $protocol . ':') !== false) {
                return true;
            }
        }

        return false;
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    public static function escapeAttribute(string $text): string
    {
        return htmlspecialchars($text, ENT_COMPAT, 'UTF-8');
    }

    public static function stripAllTags(string $html): string
    {
        return strip_tags($html);
    }

    public static function allowOnlyText(string $html): string
    {
        return strip_tags($html, '<br><p>');
    }

    public static function makeLinksClickable(string $text): string
    {
        return preg_replace(
            '/(https?:\/\/[^\s<]+)/i',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $text
        );
    }

    public static function truncateHtml(string $html, int $length, string $suffix = '...'): string
    {
        $text = self::stripAllTags($html);

        if (mb_strlen($text) <= $length) {
            return $html;
        }

        $truncatedText = mb_substr($text, 0, $length - mb_strlen($suffix)) . $suffix;

        return $truncatedText;
    }

    public static function cleanCss(string $css): string
    {
        // Remove expression()
        $css = preg_replace('/expression\s*\([^)]*\)/', '', $css);

        // Remove javascript:
        $css = preg_replace('/javascript\s*:/i', '', $css);

        // Remove behaviors
        $css = preg_replace('/behavior\s*:[^;]*;/i', '', $css);

        // Remove filter
        $css = preg_replace('/filter\s*:[^;]*;/i', '', $css);

        return $css;
    }
}
