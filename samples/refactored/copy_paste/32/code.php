<?php

namespace App\Services\Sanitization;

final class HtmlFilterConfig
{
    public readonly array $allowedTags;
    public readonly array $blockedTags;
    public readonly array $eventAttributes;

    public function __construct(
        array $allowedTags = [],
        array $blockedTags = ['script', 'style', 'iframe'],
        array $eventAttributes = ['onclick', 'onload', 'onerror']
    ) {
        $this->allowedTags = $allowedTags;
        $this->blockedTags = $blockedTags;
        $this->eventAttributes = $eventAttributes;
    }
}

final class HtmlFilterService
{
    private HtmlFilterConfig $config;

    public function __construct(HtmlFilterConfig $config)
    {
        $this->config = $config;
    }

    public function strip(string $html): string
    {
        if (!empty($this->config->allowedTags)) {
            $tags = '<' . implode('><', $this->config->allowedTags) . '>';
            return strip_tags($html, $tags);
        }

        if (!empty($this->config->blockedTags)) {
            $clean = $html;
            foreach ($this->config->blockedTags as $tag) {
                $clean = preg_replace("/<{$tag}\b[^>]*>.*?<\/{$tag}>/is", '', $clean);
                $clean = preg_replace("/<{$tag}\b[^>]*\/?>/is", '', $clean);
            }
            return $clean;
        }

        return strip_tags($html);
    }

    public function stripEventHandlers(string $html): string
    {
        if (empty($this->config->eventAttributes)) {
            return $html;
        }

        $pattern = '/\s*(' . implode('|', $this->config->eventAttributes) . ')\s*=\s*["\'][^"\']*["\']/i';

        return preg_replace($pattern, '', $html);
    }

    public function extractText(string $html): string
    {
        $clean = preg_replace('/<!--.*?-->/s', '', $html);
        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $clean);
        $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $clean);

        return trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
