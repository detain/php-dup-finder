<?php

declare(strict_types=1);

namespace App\Http\Clients;

use App\Exceptions\QueryBuilderException;

final class QueryParameterBuilder
{
    private const ENCODE_MODE = PHP_QUERY_RFC3986;

    public function build(array $params): string
    {
        if (empty($params)) {
            return '';
        }

        $encoded = $this->encodeParameters($params);

        return '?' . implode('&', $encoded);
    }

    public function buildWithExclusions(array $params, array $excludedKeys): string
    {
        $filtered = $this->filterExcluded($params, $excludedKeys);

        return $this->build($filtered);
    }

    public function buildWithPrefix(array $params, string $prefix): string
    {
        if (empty($params)) {
            return '';
        }

        $prefixed = [];

        foreach ($params as $key => $value) {
            $prefixed[$prefix . '[' . $key . ']'] = $value;
        }

        return $this->build($prefixed);
    }

    public function buildNested(array $params): string
    {
        if (empty($params)) {
            return '';
        }

        $result = [];

        foreach ($params as $key => $value) {
            $result = $this->appendNestedValue($result, $key, $value);
        }

        return '?' . http_build_query($result, '', '&', self::ENCODE_MODE);
    }

    public function buildWithEncoding(array $params, string $encoding = 'RFC3986'): string
    {
        $mode = $encoding === 'RFC1738' ? PHP_QUERY_RFC1738 : self::ENCODE_MODE;

        if (empty($params)) {
            return '';
        }

        return '?' . http_build_query($params, '', '&', $mode);
    }

    public function parseQueryString(string $query): array
    {
        if (empty($query)) {
            return [];
        }

        parse_str($query, $parsed);

        return $this->decodeValues($parsed);
    }

    public function buildFilterParams(array $filters): string
    {
        if (empty($filters)) {
            return '';
        }

        $normalized = [];

        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                foreach ($value as $idx => $item) {
                    $normalized["filter[{$field}][{$idx}]"] = $item;
                }
            } else {
                $normalized["filter[{$field}]"] = $value;
            }
        }

        return $this->build($normalized);
    }

    public function buildSortParams(array $sortFields): string
    {
        if (empty($sortFields)) {
            return '';
        }

        $normalized = [];

        foreach ($sortFields as $field => $direction) {
            $direction = strtoupper($direction) === 'DESC' ? 'desc' : 'asc';
            $normalized['sort'][] = $field . ':' . $direction;
        }

        return $this->build(['sort' => implode(',', $normalized['sort'])]);
    }

    public function buildPaginationParams(int $page, int $perPage, int $total = null): string
    {
        $params = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($total !== null) {
            $params['total'] = $total;
        }

        return $this->build($params);
    }

    private function encodeParameters(array $params): array
    {
        $result = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $index => $item) {
                    $result[] = urlencode($key) . '[' . urlencode($index) . ']=' . urlencode((string) $item);
                }
            } else {
                $result[] = urlencode($key) . '=' . urlencode((string) $value);
            }
        }

        return $result;
    }

    private function filterExcluded(array $params, array $excluded): array
    {
        $filtered = [];

        foreach ($params as $key => $value) {
            if (!in_array($key, $excluded, true)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function appendNestedValue(array $result, string $key, mixed $value): array
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $result = $this->appendNestedValue($result, $key . '[' . $k . ']', $v);
            }
        } else {
            $result[$key] = $value;
        }

        return $result;
    }

    private function decodeValues(array $parsed): array
    {
        $decoded = [];

        foreach ($parsed as $key => $value) {
            $decoded[urldecode($key)] = is_string($value) ? urldecode($value) : $value;
        }

        return $decoded;
    }
}
