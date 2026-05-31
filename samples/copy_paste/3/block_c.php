<?php
declare(strict_types=1);

namespace Acme\Http\Controllers;

final class OrdersController
{
    public function create(array $body): void
    {
        try {
            $order = $this->orderService->place($body);
            $status = 201;
            $payload = ['id' => $order->id(), 'status' => $order->status()];
        } catch (\InvalidArgumentException $e) {
            $status = 400;
            $payload = ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

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

    public function __construct(private readonly OrderService $orderService)
    {
    }
}
