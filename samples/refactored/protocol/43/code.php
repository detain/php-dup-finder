<?php

declare(strict_types=1);

namespace App\Services\Pagination;

abstract class AbstractPaginationClient
{
    protected const DEFAULT_PAGE_SIZE = 20;
    protected const MAX_PAGE_SIZE = 100;
    protected const CURSOR_TTL = 3600;
    protected const CURSOR_PREFIX = 'cursor_';
    protected const ENCODE_URL_SAFE = true;

    protected LoggerInterface $logger;

    protected abstract function createPaginationToken(array $data): string;
    protected abstract function decodePaginationToken(string $token): ?array;

    public function paginate(callable $fetcher, ?string $cursor = null, int $limit = self::DEFAULT_PAGE_SIZE): array
    {
        $token = $cursor ? $this->decodePaginationToken($cursor) : null;
        $data = $fetcher($this->buildFetchParams($limit + 1, $token));

        $hasNext = count($data) > $limit;
        $items = array_slice($data, 0, $limit);

        $result = ['data' => $items, 'has_next' => $hasNext];

        if ($hasNext && end($items)) {
            $result['next_cursor'] = $this->createPaginationToken(end($items));
        }

        return $result;
    }

    protected function encodeToken(array $payload): string
    {
        $json = json_encode($payload);
        $iv = random_bytes(openssl_cipher_iv_length('AES-256-CBC'));
        $encrypted = openssl_encrypt($json, 'AES-256-CBC', $this->getSecret(), OPENSSL_RAW_DATA, $iv);
        $combined = base64_encode($iv . $encrypted);

        if (self::ENCODE_URL_SAFE) {
            $combined = str_replace(['+', '/', '='], ['-', '_', ''], $combined);
        }

        return self::CURSOR_PREFIX . $combined;
    }

    protected function decodeToken(string $token): ?array
    {
        $token = str_starts_with($token, self::CURSOR_PREFIX) ? substr($token, strlen(self::CURSOR_PREFIX)) : $token;
        $token = str_replace(['-', '_'], ['+', '/'], $token);
        $decoded = base64_decode($token, true);
        if (!$decoded) return null;

        $ivLen = openssl_cipher_iv_length('AES-256-CBC');
        $decrypted = openssl_decrypt(substr($decoded, $ivLen), 'AES-256-CBC', $this->getSecret(), OPENSSL_RAW_DATA, substr($decoded, 0, $ivLen));

        return $decrypted ? json_decode($decrypted, true) : null;
    }

    abstract protected function getSecret(): string;
    abstract protected function buildFetchParams(int $limit, ?array $cursor): array;
}
