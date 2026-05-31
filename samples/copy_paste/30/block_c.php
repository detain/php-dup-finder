<?php

declare(strict_types=1);

namespace App\Routing;

use App\Exceptions\UrlParameterException;

final class RequestQueryComposer
{
    private const ENCODING_RFC3986 = PHP_QUERY_RFC3986;
    private const ENCODING_RFC1738 = PHP_QUERY_RFC1738;

    public function compose(array $params): string
    {
        if (empty($params)) {
            return '';
        }

        $params = $this->prepareParameters($params);

        return '?' . http_build_query($params, '', '&', self::ENCODING_RFC3986);
    }

    public function composeExcept(array $params, array $exceptKeys): string
    {
        $params = array_diff_key($params, array_flip($exceptKeys));

        return $this->compose($params);
    }

    public function composePrefixed(array $params, string $prefix): string
    {
        $prefixed = [];

        foreach ($params as $key => $value) {
            $prefixed["{$prefix}[{$key}]"] = $value;
        }

        return $this->compose($prefixed);
    }

    public function composeFlattened(array $params): string
    {
        if (empty($params)) {
            return '';
        }

        $flat = [];
        $this->makeFlat($params, '', $flat);

        return '?' . http_build_query($flat, '', '&', self::ENCODING_RFC3986);
    }

    public function composeWithStandard(array $params, string $standard = 'RFC3986'): string
    {
        $encoding = $standard === 'RFC1738' ? self::ENCODING_RFC1738 : self::ENCODING_RFC3986;

        if (empty($params)) {
            return '';
        }

        return '?' . http_build_query($params, '', '&', $encoding);
    }

    public function parse(string $query): array
    {
        $query = ltrim($query, '?');

        if (empty($query)) {
            return [];
        }

        parse_str($query, $parsed);

        return $this->decodeRecursively($parsed);
    }

    public function composePage(int $number, int $size): string
    {
        return $this->compose(['page' => $number, 'page_size' => $size]);
    }

    public function composeRange(int $offset, int $limit): string
    {
        return $this->compose(['offset' => $offset, 'limit' => $limit]);
    }

    public function composeCursor(string $cursor, array $extras = []): string
    {
        $params = array_merge(['cursor' => $cursor], $extras);

        return $this->compose($params);
    }

    public function composeSorting(array $sortConfig): string
    {
        $parts = [];

        foreach ($sortConfig as $field => $direction) {
            $parts[] = $field . '|' . (strtoupper($direction) === 'DESC' ? 'desc' : 'asc');
        }

        return $this->compose(['sort' => implode(',', $parts)]);
    }

    private function prepareParameters(array $params): array
    {
        $prepared = [];

        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $prepared[$key] = $value ? 'true' : 'false';
            } elseif (is_scalar($value)) {
                $prepared[$key] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $k => $v) {
                    $prepared["{$key}[{$k}]"] = $v;
                }
            }
        }

        return $prepared;
    }

    private function makeFlat(array $params, string $prefix, array &$result): void
    {
        foreach ($params as $key => $value) {
            $newKey = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";

            if (is_array($value)) {
                $this->makeFlat($value, $newKey, $result);
            } else {
                $result[$newKey] = $value;
            }
        }
    }

    private function decodeRecursively(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $decodedKey = urldecode($key);

            if (is_array($value)) {
                $result[$decodedKey] = $this->decodeRecursively($value);
            } else {
                $result[$decodedKey] = is_string($value) ? urldecode($value) : $value;
            }
        }

        return $result;
    }
}
