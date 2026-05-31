<?php
declare(strict_types=1);

namespace Acme\Api\Invoices;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class ListInvoicesController
{
    public function __construct(private \PDO $db) {}

    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        $q = $req->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = (int) ($q['size'] ?? 25);
        if ($size < 1) {
            $size = 25;
        }
        if ($size > 200) {
            $size = 200;
        }
        $sort = (string) ($q['sort'] ?? '-created_at');
        $sortCol = ltrim($sort, '-');
        $sortDir = str_starts_with($sort, '-') ? 'DESC' : 'ASC';

        $stmt = $this->db->prepare(
            'SELECT id, customer_id, total_cents, status, created_at
               FROM invoices
              ORDER BY ' . $sortCol . ' ' . $sortDir . '
              LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue('lim', $size, \PDO::PARAM_INT);
        $stmt->bindValue('off', ($page - 1) * $size, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resp = new Response();
        $resp->getBody()->write(json_encode([
            'page' => $page,
            'size' => $size,
            'data' => $rows,
        ], JSON_THROW_ON_ERROR));

        return $resp->withHeader('Content-Type', 'application/json');
    }
}
