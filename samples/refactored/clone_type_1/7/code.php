<?php
declare(strict_types=1);

namespace Acme\Controllers\Support;

final class JsonEnvelopeBuilder
{
    /**
     * Build the standard JSON envelope around a data/meta pair.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public static function build(array $data, array $meta): array
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
}

// Each controller now calls JsonEnvelopeBuilder::build($data, $meta).
