<?php

declare(strict_types=1);

namespace App\Filters;

use App\Exceptions\FilteringException;

final class HtmlContentFilter
{
    private const PROHIBITED_ELEMENTS = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'noscript'];
    private const PERMITTED_ELEMENTS = ['p', 'br', 'strong', 'em', 'b', 'i', 'u', 'a', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public function filterToPlainText(string $html): string
    {
        $text = $this->stripHtmlComments($html);
        $text = $this->stripStyleElements($text);
        $text = $this->stripScriptElements($text);
        $text = strip_tags($text);
        $text = $this->decodeHtmlEntities($text);

        return trim($text);
    }

    public function filterAllowingBasic(string $html, array $basicTags = self::PERMITTED_ELEMENTS): string
    {
        if (empty($basicTags)) {
            return strip_tags($html);
        }

        $tagString = '<' . implode('><', $basicTags) . '>';

        return strip_tags($html, $tagString);
    }

    public function filterBlockingDangerous(string $html, array $dangerousTags = self::PROHIBITED_ELEMENTS): string
    {
        $content = $html;

        foreach ($dangerousTags as $tag) {
            $content = $this->eliminateTag($content, $tag);
        }

        return $content;
    }

    public function filterRemovingComments(string $html): string
    {
        return preg_replace('/<!--[\s\S]*?-->/', '', $html);
    }

    public function filterRemovingScripts(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

        return preg_replace('/<script\b[^>]*\/?>/is', '', $html);
    }

    public function filterRemovingStyles(string $html): string
    {
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        return preg_replace('/<style\b[^>]*\/?>/is', '', $html);
    }

    public function filterRemovingAnchors(string $html): string
    {
        return preg_replace('/<a\b[^>]*>.*?<\/a>/is', '', $html);
    }

    public function filterRemovingImages(string $html): string
    {
        return preg_replace('/<img\b[^>]*\/?>/is', '', $html);
    }

    public function filterRemovingForms(string $html): string
    {
        return preg_replace('/<form\b[^>]*>.*?<\/form>/is', '', $html);
    }

    public function filterRemovingEventHandlers(string $html): string
    {
        $handlers = [
            'onclick', 'oncontextmenu', 'ondblclick', 'onmousedown', 'onmouseenter',
            'onmouseleave', 'onmousemove', 'onmouseout', 'onmouseover', 'onmouseup',
            'onkeydown', 'onkeypress', 'onkeyup', 'onabort', 'onblur', 'oncanplay',
            'oncanplaythrough', 'onchange', 'onclick', 'oncontextmenu', 'oncopy',
            'oncut', 'ondblclick', 'ondrag', 'ondragend', 'ondragenter', 'ondragleave',
            'ondragover', 'ondragstart', 'ondrop', 'ondurationchange', 'onemptied',
            'onended', 'onerror', 'onfocus', 'oninput', 'oninvalid', 'onkeydown',
        ];

        $pattern = '/\s+(' . implode('|', $handlers) . ')\s*=\s*["\'][^"\']*["\']/i';

        return preg_replace($pattern, '', $html);
    }

    public function filterRemovingJavascriptBindings(string $html): string
    {
        $patterns = [
            '/href\s*=\s*["\']javascript:[^"\']*["\']/i',
            '/src\s*=\s*["\']javascript:[^"\']*["\']/i',
        ];

        foreach ($patterns as $p) {
            $html = preg_replace($p, '', $html);
        }

        return $html;
    }

    public function purifyForDisplay(string $html): string
    {
        $purified = $this->filterRemovingComments($html);
        $purified = $this->filterRemovingScripts($purified);
        $purified = $this->filterRemovingStyles($purified);
        $purified = $this->filterRemovingEventHandlers($purified);
        $purified = $this->filterRemovingJavascriptBindings($purified);

        return trim($purified);
    }

    private function stripHtmlComments(string $html): string
    {
        return preg_replace('/<!--.*?-->/s', '', $html);
    }

    private function stripStyleElements(string $html): string
    {
        return preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
    }

    private function stripScriptElements(string $html): string
    {
        return preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    }

    private function eliminateTag(string $html, string $tag): string
    {
        $html = preg_replace('/<' . preg_quote($tag, '/') . '\b[^>]*>.*?<\/' . preg_quote($tag, '/') . '>/is', '', $html);

        return preg_replace('/<' . preg_quote($tag, '/') . '\b[^>]*\/?>/is', '', $html);
    }

    private function decodeHtmlEntities(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
