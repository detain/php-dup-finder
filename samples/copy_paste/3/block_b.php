<?php
declare(strict_types=1);

namespace Acme\Http\Controllers;

final class ProductsController
{
    public function __construct(private readonly ProductCatalog $catalog)
    {
    }

    public function index(array $query): void
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));
        $products = $this->catalog->list($page, $perPage);

        $status = 200;
        $payload = [
            'data' => array_map(fn ($p) => ['id' => $p->id(), 'name' => $p->name()], $products),
            'page' => $page,
        ];

        // ---- BEGIN copy-pasted response builder ----
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            http_response_code(500);
            echo '{"error":"encoding_failed"}';
            return;
        }
        header('Content-Length: ' . strlen($json));
        echo $json;
        // ---- END copy-pasted response builder ----
    }
}
