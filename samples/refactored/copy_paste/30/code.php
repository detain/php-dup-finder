<?php

namespace App\Services\Http;

final class QueryStringConfig
{
    public readonly int $encoding;
    public readonly bool $encodeBrackets;

    public function __construct(
        int $encoding = PHP_QUERY_RFC3986,
        bool $encodeBrackets = true
    ) {
        $this->encoding = $encoding;
        $this->encodeBrackets = $encodeBrackets;
    }
}

final class QueryStringService
{
    private QueryStringConfig $config;

    public function __construct(QueryStringConfig $config)
    {
        $this->config = $config;
    }

    public function build(array $params): string
    {
        if (empty($params)) {
            return '';
        }

        return '?' . http_build_query($params, '', '&', $this->config->encoding);
    }

    public function buildFiltered(array $params, array $exclude): string
    {
        $filtered = array_filter(
            $params,
            fn($key) => !in_array($key, $exclude, true),
            ARRAY_FILTER_USE_KEY
        );

        return $this->build($filtered);
    }

    public function buildPrefixed(array $params, string $prefix): string
    {
        $prefixed = [];

        foreach ($params as $key => $value) {
            $prefixed["{$prefix}[{$key}]"] = $value;
        }

        return $this->build($prefixed);
    }

    public function parse(string $query): array
    {
        $query = ltrim($query, '?');

        if (empty($query)) {
            return [];
        }

        parse_str($query, $result);

        return array_map('urldecode', $result);
    }
}
