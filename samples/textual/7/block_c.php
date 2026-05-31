<?php
declare(strict_types=1);

namespace Catalog\Api\Builders;

final class PublisherListResponseBuilder
{
    /** @param list<array<string,mixed>> $publishers */
    public function build(array $publishers, int $page, int $perPage, int $total): array
    {
        $paginationSchema = <<<'JSON'
        {
          "type": "object",
          "required": ["page", "per_page", "total", "total_pages", "has_more"],
          "properties": {
            "page": {"type": "integer", "minimum": 1, "description": "Current page (1-based)"},
            "per_page": {"type": "integer", "minimum": 1, "maximum": 200, "description": "Items per page"},
            "total": {"type": "integer", "minimum": 0, "description": "Total matching records"},
            "total_pages": {"type": "integer", "minimum": 0, "description": "Total number of pages"},
            "has_more": {"type": "boolean", "description": "True if more pages remain"}
          },
          "additionalProperties": false
        }
        JSON;

        return [
            'data' => $publishers,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'has_more' => $page * $perPage < $total,
            ],
            '_schema' => ['pagination' => json_decode($paginationSchema, true)],
        ];
    }
}
