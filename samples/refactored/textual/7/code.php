<?php
declare(strict_types=1);

namespace Catalog\Api\Builders;

final class PaginationSchema
{
    private const JSON = <<<'JSON'
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

    /** @return array<string,mixed> */
    public static function definition(): array
    {
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode(self::JSON, true, flags: JSON_THROW_ON_ERROR);
        return $decoded;
    }

    /** @return array<string,int|bool> */
    public static function envelope(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int) ceil($total / max(1, $perPage)),
            'has_more' => $page * $perPage < $total,
        ];
    }
}

final class BookListResponseBuilder
{
    /** @param list<array<string,mixed>> $books */
    public function build(array $books, int $page, int $perPage, int $total): array
    {
        return [
            'data' => $books,
            'pagination' => PaginationSchema::envelope($page, $perPage, $total),
            '_schema' => ['pagination' => PaginationSchema::definition()],
        ];
    }
}
