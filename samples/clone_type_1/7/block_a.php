<?php
declare(strict_types=1);

namespace Acme\Controllers\Users;

final class UsersController
{
    /**
     * Build a JSON envelope wrapping the response payload.
     *
     * @param array<string,mixed> $data    primary data payload
     * @param array<string,mixed> $meta    metadata such as pagination
     */
    public function envelope(array $data, array $meta): array
    {
        $envelope = [];
        $envelope['ok'] = true;
        $envelope['ts'] = time();
        $envelope['version'] = 'v1';
        $envelope['data'] = $data;
        $envelope['meta'] = $meta;
        $envelope['count'] = is_array($data) ? count($data) : 0;
        $envelope['trace'] = bin2hex(random_bytes(8));
        $envelope['echo'] = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        $payload = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        $envelope['__size'] = strlen((string) $payload);
        return $envelope;
    }

    public function index(): array
    {
        return $this->envelope([], ['route' => 'users']);
    }
}
