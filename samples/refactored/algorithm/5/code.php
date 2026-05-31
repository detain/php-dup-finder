<?php

declare(strict_types=1);

namespace Acme\Common\Pagination;

use PDO;

final class KeysetPaginator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @template TItem
     * @param callable(array<string,mixed>):TItem $mapRow
     * @return array{items: list<TItem>, nextCursor: ?string}
     */
    public function paginate(
        string $sql,
        string $sortColumn,
        string $idColumn,
        ?string $cursor,
        int $limit,
        callable $mapRow,
    ): array {
        $lastValue = '1970-01-01 00:00:00';
        $lastId = 0;

        if ($cursor !== null && $cursor !== '') {
            $decoded = base64_decode($cursor, true);
            if ($decoded === false) {
                throw new \InvalidArgumentException('Invalid cursor');
            }
            [$lastValue, $lastId] = explode('|', $decoded, 2);
            $lastId = (int) $lastId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':sort', $lastValue);
        $stmt->bindValue(':id', $lastId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map($mapRow, $rows);

        $next = null;
        if (count($items) === $limit) {
            $tail = end($rows);
            $next = base64_encode($tail[$sortColumn] . '|' . $tail[$idColumn]);
        }

        return ['items' => $items, 'nextCursor' => $next];
    }
}
