<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Exceptions\RequestParameterException;

final class HttpQueryStringFactory
{
    private const RFC_3986 = PHP_QUERY_RFC3986;
    private const RFC_1738 = PHP_QUERY_RFC1738;

    public function construct(array $parameters): string
    {
        if (empty($parameters)) {
            return '';
        }

        $encoded = $this->processParameters($parameters);

        return '?' . implode('&', $encoded);
    }

    public function constructFiltered(array $parameters, array $blockedKeys): string
    {
        $clean = array_filter(
            $parameters,
            fn($key) => !in_array($key, $blockedKeys, true),
            ARRAY_FILTER_USE_KEY
        );

        return $this->construct($clean);
    }

    public function constructWithNamespace(array $parameters, string $ns): string
    {
        if (empty($parameters)) {
            return '';
        }

        $namespaced = [];

        foreach ($parameters as $key => $value) {
            $namespaced["{$ns}[{$key}]"] = $value;
        }

        return $this->construct($namespaced);
    }

    public function constructNested(array $parameters): string
    {
        if (empty($parameters)) {
            return '';
        }

        $flat = [];
        $this->flattenParameters($parameters, '', $flat);

        return '?' . http_build_query($flat, '', '&', self::RFC_3986);
    }

    public function constructWithEncoding(array $parameters, string $encodingStandard): string
    {
        $encoding = $encodingStandard === 'RFC1738' ? self::RFC_1738 : self::RFC_3986;

        if (empty($parameters)) {
            return '';
        }

        return '?' . http_build_query($parameters, '', '&', $encoding);
    }

    public function decode(string $queryString): array
    {
        if (empty($queryString)) {
            return [];
        }

        $queryString = ltrim($queryString, '?');

        parse_str($queryString, $result);

        return $this->decodeKeysAndValues($result);
    }

    public function constructIncludes(array $includes): string
    {
        if (empty($includes)) {
            return '';
        }

        $includes = array_map('trim', $includes);

        return $this->construct(['include' => implode(',', $includes)]);
    }

    public function constructFields(array $resources): string
    {
        if (empty($resources)) {
            return '';
        }

        $fields = [];

        foreach ($resources as $resource => $fieldList) {
            if (is_array($fieldList)) {
                $fields["fields[{$resource}]"] = implode(',', $fieldList);
            }
        }

        return $this->construct($fields);
    }

    public function constructSparseFieldset(array $fields): string
    {
        if (empty($fields)) {
            return '';
        }

        $parsed = [];

        foreach ($fields as $type => $fieldNames) {
            if (is_array($fieldNames)) {
                $parsed["fields[{$type}]"] = implode(',', $fieldNames);
            }
        }

        return $this->construct($parsed);
    }

    private function processParameters(array $params): array
    {
        $processed = [];

        foreach ($params as $key => $value) {
            if (is_scalar($value)) {
                $processed[] = urlencode((string) $key) . '=' . urlencode((string) $value);
            } elseif (is_array($value)) {
                foreach ($value as $index => $item) {
                    $processed[] = urlencode((string) $key) . '[' . urlencode((string) $index) . ']=' . urlencode((string) $item);
                }
            }
        }

        return $processed;
    }

    private function flattenParameters(array $params, string $prefix, array &$result): void
    {
        foreach ($params as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                $this->flattenParameters($value, $fullKey, $result);
            } else {
                $result[$fullKey] = $value;
            }
        }
    }

    private function decodeKeysAndValues(array $data): array
    {
        $decoded = [];

        foreach ($data as $key => $value) {
            $decodedKey = urldecode($key);
            $decodedValue = is_string($value) ? urldecode($value) : $value;
            $decoded[$decodedKey] = $decodedValue;
        }

        return $decoded;
    }
}
