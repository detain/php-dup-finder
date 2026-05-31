<?php

declare(strict_types=1);

namespace App\Services\Api;

use Illuminate\Http\Request;

final class QueryParameterParser
{
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 100;
    private const MIN_PER_PAGE = 1;
    private const DEFAULT_SORT = '-created_at';
    private const ALLOWED_SORT_PREFIXES = ['-', '+'];
    private const ALLOWED_SORT_FIELDS = ['id', 'name', 'created_at', 'updated_at', 'email', 'status', 'priority'];
    private const FILTER_DELIMITER = ',';
    private const RANGE_DELIMITER = '-';
    private const DATE_FORMAT = 'Y-m-d';
    private const DEFAULT_TIMEZONE = 'UTC';
    private const MAX_INCLUDE_DEPTH = 3;
    private const PARSE_BOOLEAN_STRINGS = ['true', 'false', '1', '0', 'yes', 'no'];

    public function parsePageParams(Request $request): array
    {
        $page = $request->input('page', self::DEFAULT_PAGE);
        $perPage = $request->input('per_page', self::DEFAULT_PER_PAGE);

        if (is_string($page)) {
            $page = (int) $page;
        }
        if (is_string($perPage)) {
            $perPage = (int) $perPage;
        }

        $page = max(1, $page);
        $perPage = $this->constrainPerPage($perPage);

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
    }

    public function parseSortParam(Request $request): array
    {
        $sortParam = $request->input('sort', self::DEFAULT_SORT);

        if (empty($sortParam)) {
            return ['field' => 'created_at', 'direction' => 'desc'];
        }

        $direction = 'asc';

        if (in_array(substr($sortParam, 0, 1), self::ALLOWED_SORT_PREFIXES, true)) {
            if (substr($sortParam, 0, 1) === '-') {
                $direction = 'desc';
            }
            $sortParam = substr($sortParam, 1);
        }

        if (!in_array($sortParam, self::ALLOWED_SORT_FIELDS, true)) {
            $sortParam = 'created_at';
        }

        return [
            'field' => $sortParam,
            'direction' => $direction,
        ];
    }

    public function parseFilters(Request $request): array
    {
        $filters = [];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, ['page', 'per_page', 'sort', 'include', 'fields', 'search'], true)) {
                continue;
            }

            if (str_starts_with($key, 'filter_')) {
                $filterName = substr($key, 7);
                $filters[$filterName] = $this->parseFilterValue($value);
            } elseif (is_string($value) && str_contains($value, self::FILTER_DELIMITER)) {
                $filters[$key] = explode(self::FILTER_DELIMITER, $value);
            } else {
                $filters[$key] = $this->parseFilterValue($value);
            }
        }

        return $filters;
    }

    private function parseFilterValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $lowerValue = strtolower($value);

            if (in_array($lowerValue, self::PARSE_BOOLEAN_STRINGS, true)) {
                return in_array($lowerValue, ['true', '1', 'yes'], true);
            }

            if (str_contains($value, self::RANGE_DELIMITER) && preg_match('/^\d+-\d+$/', $value)) {
                $parts = explode(self::RANGE_DELIMITER, $value);
                return [
                    'min' => (int) $parts[0],
                    'max' => (int) $parts[1],
                    'operator' => 'between',
                ];
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return \DateTime::createFromFormat(self::DATE_FORMAT, $value);
            }
        }

        return $value;
    }

    public function parseIncludes(Request $request): array
    {
        $includeParam = $request->input('include', '');

        if (empty($includeParam)) {
            return [];
        }

        $includes = array_filter(
            array_map('trim', explode(',', $includeParam)),
            fn($include) => !empty($include) && $this->isValidInclude($include)
        );

        return array_slice($includes, 0, self::MAX_INCLUDE_DEPTH);
    }

    private function isValidInclude(string $include): bool
    {
        return preg_match('/^[a-z_][a-z0-9_]*$/i', $include);
    }

    public function parseFields(Request $request): array
    {
        $fieldsParam = $request->input('fields', '');
        $fields = [];

        if (empty($fieldsParam)) {
            return $fields;
        }

        foreach (explode(',', $fieldsParam) as $field) {
            $parts = explode(':', $field);

            if (count($parts) === 2) {
                $resource = trim($parts[0]);
                $fieldList = array_filter(array_map('trim', explode(' ', trim($parts[1]))));

                if (!isset($fields[$resource])) {
                    $fields[$resource] = [];
                }

                $fields[$resource] = array_merge($fields[$resource], $fieldList);
            }
        }

        return $fields;
    }

    public function parseSearch(Request $request): ?string
    {
        $search = $request->input('search', '');

        if (empty($search) || strlen($search) < self::DEFAULT_PAGE) {
            return null;
        }

        return trim($search);
    }

    private function constrainPerPage(int $perPage): int
    {
        return max(self::MIN_PER_PAGE, min($perPage, self::MAX_PER_PAGE));
    }

    public function getDefaultPerPage(): int
    {
        return self::DEFAULT_PER_PAGE;
    }

    public function getMaxPerPage(): int
    {
        return self::MAX_PER_PAGE;
    }
}
