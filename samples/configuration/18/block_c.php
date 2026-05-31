<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class PaginatedResourceCollection extends ResourceCollection
{
    private const DEFAULT_PAGINATION_KEY = 'data';
    private const META_PAGE_KEY = 'page';
    private const META_PER_PAGE_KEY = 'per_page';
    private const META_TOTAL_KEY = 'total';
    private const META_LAST_PAGE_KEY = 'last_page';
    private const META_CURRENT_PAGE_KEY = 'current_page';
    private const LINKS_SELF = 'self';
    private const LINKS_FIRST = 'first';
    private const LINKS_LAST = 'last';
    private const LINKS_PREV = 'prev';
    private const LINKS_NEXT = 'next';
    private const DEFAULT_PAGE_RANGE = 5;
    private const INCLUDE_TOTAL_COUNT = true;
    private const INCLUDE_PAGE_RANGE = true;
    private const INCLUDE_LINKS = true;

    private int $page;
    private int $perPage;
    private int $total;
    private int $lastPage;
    private ?string $sortField;
    private ?string $sortDirection;
    private array $filters;
    private ?string $search;

    public function __construct($resource, int $page = 1, int $perPage = 20, ?int $total = null, ?int $lastPage = null)
    {
        parent::__construct($resource);

        $this->page = $page;
        $this->perPage = $perPage;
        $this->total = $total ?? 0;
        $this->lastPage = $lastPage ?? 1;
    }

    public function withSorting(?string $sortField, ?string $sortDirection): self
    {
        $this->sortField = $sortField;
        $this->sortDirection = $sortDirection;
        return $this;
    }

    public function withFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }

    public function withSearch(?string $search): self
    {
        $this->search = $search;
        return $this;
    }

    public function toArray(Request $request): array
    {
        return $this->buildResponse();
    }

    private function buildResponse(): array
    {
        $response = [
            self::META_PAGE_KEY => $this->page,
            self::META_PER_PAGE_KEY => $this->perPage,
            self::META_TOTAL_KEY => $this->total,
            self::META_LAST_PAGE_KEY => $this->lastPage,
        ];

        if (self::INCLUDE_TOTAL_COUNT) {
            $response['total_count'] = $this->total;
        }

        if ($this->sortField !== null) {
            $response['sort'] = [
                'field' => $this->sortField,
                'direction' => $this->sortDirection ?? 'asc',
            ];
        }

        if (!empty($this->filters)) {
            $response['filters'] = $this->filters;
        }

        if ($this->search !== null) {
            $response['search'] = $this->search;
        }

        if (self::INCLUDE_LINKS) {
            $response['links'] = $this->buildLinks();
        }

        if (self::INCLUDE_PAGE_RANGE) {
            $response['page_range'] = $this->buildPageRange();
        }

        $response[self::PAGINATION_KEY] = $this->collection->toArray();

        return $response;
    }

    private function buildLinks(): array
    {
        $links = [
            self::LINKS_SELF => $this->buildUrl($this->page),
            self::LINKS_FIRST => $this->buildUrl(1),
            self::LINKS_LAST => $this->buildUrl($this->lastPage),
        ];

        if ($this->page > 1) {
            $links[self::LINKS_PREV] = $this->buildUrl($this->page - 1);
        }

        if ($this->page < $this->lastPage) {
            $links[self::LINKS_NEXT] = $this->buildUrl($this->page + 1);
        }

        return $links;
    }

    private function buildUrl(int $page): string
    {
        $params = [
            'page' => $page,
            'per_page' => $this->perPage,
        ];

        if ($this->sortField !== null) {
            $prefix = $this->sortDirection === 'desc' ? '-' : '';
            $params['sort'] = $prefix . $this->sortField;
        }

        if (!empty($this->filters)) {
            foreach ($this->filters as $key => $value) {
                $params['filter_' . $key] = is_array($value) ? implode(',', $value) : $value;
            }
        }

        if ($this->search !== null) {
            $params['search'] = $this->search;
        }

        return url()->current() . '?' . http_build_query($params);
    }

    private function buildPageRange(): array
    {
        $current = $this->page;
        $last = $this->lastPage;
        $delta = self::DEFAULT_PAGE_RANGE;

        $range = [];
        $rangeWithDots = [];

        for ($i = 1; $i <= $last; $i++) {
            if ($i === 1 || $i === $last || abs($i - $current) <= $delta) {
                $range[] = $i;
            }
        }

        foreach ($range as $index => $pageNum) {
            if ($index > 0) {
                if ($pageNum - $range[$index - 1] > 1) {
                    $rangeWithDots[] = '...';
                }
            }
            $rangeWithDots[] = $pageNum;
        }

        return $rangeWithDots;
    }

    public static function paginationKey(): string
    {
        return self::DEFAULT_PAGINATION_KEY;
    }

    public static function metaPageKey(): string
    {
        return self::META_PAGE_KEY;
    }

    public static function metaPerPageKey(): string
    {
        return self::META_PER_PAGE_KEY;
    }
}
