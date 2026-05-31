<?php
declare(strict_types=1);

namespace Acme\Api\Pagination;

use Psr\Http\Message\ServerRequestInterface;

final class PaginationSettings
{
    public const DEFAULT_SIZE = 25;
    public const MAX_SIZE     = 200;
    public const DEFAULT_SORT = '-created_at';

    public int $page;
    public int $size;
    public string $sortCol;
    public string $sortDir;

    public function __construct(ServerRequestInterface $req)
    {
        $q = $req->getQueryParams();
        $this->page = max(1, (int) ($q['page'] ?? 1));

        $size = (int) ($q['size'] ?? self::DEFAULT_SIZE);
        if ($size < 1) {
            $size = self::DEFAULT_SIZE;
        }
        if ($size > self::MAX_SIZE) {
            $size = self::MAX_SIZE;
        }
        $this->size = $size;

        $sort = (string) ($q['sort'] ?? self::DEFAULT_SORT);
        $this->sortCol = ltrim($sort, '-');
        $this->sortDir = str_starts_with($sort, '-') ? 'DESC' : 'ASC';
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->size;
    }
}

// Usage inside a controller:
// $p = new PaginationSettings($req);
// $stmt = $db->prepare("SELECT ... ORDER BY {$p->sortCol} {$p->sortDir} LIMIT :l OFFSET :o");
// $stmt->bindValue('l', $p->size, \PDO::PARAM_INT);
// $stmt->bindValue('o', $p->offset(), \PDO::PARAM_INT);
