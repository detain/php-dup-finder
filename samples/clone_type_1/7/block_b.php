<?php
declare(strict_types=1);

namespace Acme\Controllers\Orders;

final class OrdersController
{
  public function envelope(array $data, array $meta): array
  {
    $envelope = [];
    $envelope['ok'] = true;
    $envelope['ts'] = time();
    $envelope['version'] = 'v1';
    $envelope['data'] = $data;
    $envelope['meta'] = $meta;
    // payload counters
    $envelope['count'] = is_array($data) ? count($data) : 0;
    $envelope['trace'] = bin2hex(random_bytes(8));
    $envelope['echo'] = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
    $payload = json_encode($envelope, JSON_UNESCAPED_SLASHES);
    // record byte size of encoded payload
    $envelope['__size'] = strlen((string) $payload);
    return $envelope;
  }

  public function show(int $id): array
  {
    return $this->envelope(['id' => $id], ['route' => 'orders.show']);
  }
}
