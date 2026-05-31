<?php

declare(strict_types=1);

namespace App\Content;

use App\Exceptions\ContentCleaningException;

final class UserInputSanitizer
{
    private const DANGEROUS_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'link', 'meta'];
    private const BASIC_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'a', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public function removeAllHtml(string $input): string
    {
        return strip_tags($input);
    }

    public function removeHtmlExceptAllowed(string $input, array $allowed = self::BASIC_TAGS): string
    {
        if (empty($allowed)) {
            return $this->removeAllHtml($input);
        }

        $tagList = '<' . implode('><', $allowed) . '>';

        return strip_tags($input, $tagList);
    }

    public function removeHtmlExceptDenied(string $input, array $denied = self::DANGEROUS_TAGS): string
    {
        $clean = $input;

        foreach ($denied as $tag) {
            $pattern = '/<' . preg_quote($tag, '/') . '\b[^>]*>.*?<\/' . preg_quote($tag, '/') . '>/is';
            $clean = preg_replace($pattern, '', $clean);

            $pattern = '/<' . preg_quote($tag, '/') . '\b[^>]*\/?>/is';
            $clean = preg_replace($pattern, '', $clean);
        }

        return $clean;
    }

    public function removeCommentsFromHtml(string $input): string
    {
        return preg_replace('/<!--.*?-->/s', '', $input);
    }

    public function removeScriptTags(string $input): string
    {
        $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $input);

        return preg_replace('/<script\b[^>]*\/?>/is', '', $clean);
    }

    public function removeStyleBlocks(string $input): string
    {
        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $input);

        return preg_replace('/<style\b[^>]*\/?>/is', '', $clean);
    }

    public function removeAnchorTags(string $input): string
    {
        return preg_replace('/<a\b[^>]*>.*?<\/a>/is', '', $input);
    }

    public function removeImageTags(string $input): string
    {
        return preg_replace('/<img\b[^>]*\/?>/is', '', $input);
    }

    public function removeFormTags(string $input): string
    {
        return preg_replace('/<form\b[^>]*>.*?<\/form>/is', '', $input);
    }

    public function removeOnClickAttributes(string $input): string
    {
        return preg_replace('/\sonclick\s*=\s*["\'][^"\']*["\']/i', '', $input);
    }

    public function removeOnLoadAttributes(string $input): string
    {
        return preg_replace('/\sonload\s*=\s*["\'][^"\']*["\']/i', '', $input);
    }

    public function removeAllEventAttributes(string $input): string
    {
        $events = [
            'onclick', 'oncontextmenu', 'ondblclick', 'onmousedown', 'onmousemove',
            'onmouseout', 'onmouseover', 'onmouseup', 'onkeydown', 'onkeypress',
            'onkeyup', 'onabort', 'onblur', 'onchange', 'onerror', 'onfocus',
            'oninput', 'oninvalid', 'onreset', 'onsearch', 'onselect', 'onsubmit',
            'onunload', 'onload', 'onerror', 'oncanplay', 'oncanplaythrough',
        ];

        $pattern = '/\s*(' . implode('|', $events) . ')\s*=\s*["\'][^"\']*["\']/i';

        return preg_replace($pattern, '', $input);
    }

    public function removeJavaScriptProtocol(string $input): string
    {
        $patterns = [
            '/href\s*=\s*["\']javascript:[^"\']*["\']/i',
            '/src\s*=\s*["\']javascript:[^"\']*["\']/i',
            '/action\s*=\s*["\']javascript:[^"\']*["\']/i',
        ];

        foreach ($patterns as $pattern) {
            $input = preg_replace($pattern, '', $input);
        }

        return $input;
    }

    public function cleanForDisplay(string $input): string
    {
        $clean = $this->removeCommentsFromHtml($input);
        $clean = $this->removeScriptTags($clean);
        $clean = $this->removeStyleBlocks($clean);
        $clean = $this->removeAllEventAttributes($clean);
        $clean = $this->removeJavaScriptProtocol($clean);

        return trim($clean);
    }

    public function extractPlainText(string $input): string
    {
        $clean = $this->removeCommentsFromHtml($input);
        $clean = $this->removeScriptTags($clean);
        $clean = $this->removeStyleBlocks($clean);
        $clean = strip_tags($clean);

        return trim(html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
