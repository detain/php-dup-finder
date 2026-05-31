<?php

declare(strict_types=1);

namespace App\Api;

use Psr\Log\LoggerInterface;

final class OffsetPaginationHandler
{
    private const DEFAULT_OFFSET = 0;
    private const DEFAULT_COUNT = 20;
    private const MAX_COUNT = 100;
    private const MIN_COUNT = 1;
    private const PAGE_TOKEN_SECRET = 'page-token-secret-key-32-characters';
    private const PAGE_TOKEN_ALGO = 'AES-256-CBC';
    private const PAGE_TOKEN_PREFIX = 'pg_';
    private const PAGE_TOKEN_LIFETIME = 3600;
    private const URLSAFE = true;
    private const SHOW_TOTAL = true;
    private const SHOW_NEXT_PAGE = true;
    private const SHOW_PREV_PAGE = false;
    private const DEFAULT_ORDER = 'created_at';
    private const DEFAULT_DIRECTION = 'desc';
    private const MAX_OFFSET_AGE = 86400;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function paginate(
        callable $dataLoader,
        int $offset = 0,
        int $count = self::DEFAULT_COUNT,
        array $sorting = []
    ): array {
        $offset = max(0, $offset);
        $count = $this->boundCount($count);
        $sorting = $this->normalizeSorting($sorting);

        $fetchOffset = $offset;
        $fetchCount = $count + 1;

        $result = $dataLoader($fetchOffset, $fetchCount, $sorting);

        $items = $result['items'] ?? $result;
        $total = $result['total'] ?? count($items);

        $hasMore = count($items) > $count;
        $paginatedItems = array_slice($items, 0, $count);

        $nextOffset = null;
        if ($hasMore) {
            $nextOffset = $offset + $count;
        }

        $output = [
            'items' => $paginatedItems,
            'pagination' => [
                'offset' => $offset,
                'count' => $count,
                'has_more' => $hasMore,
            ],
        ];

        if (self::SHOW_TOTAL) {
            $output['pagination']['total'] = $total;
        }

        if (self::SHOW_NEXT_PAGE && $nextOffset !== null) {
            $output['pagination']['next_offset'] = $nextOffset;
            $output['pagination']['next_page_token'] = $this->createPageToken($nextOffset, $sorting);
        }

        if (self::SHOW_PREV_PAGE && $offset > 0) {
            $prevOffset = max(0, $offset - $count);
            $output['pagination']['has_previous'] = true;
            $output['pagination']['previous_page_token'] = $this->createPageToken($prevOffset, $sorting);
        }

        return $output;
    }

    public function createPageToken(int $offset, array $sorting = []): string
    {
        $payload = [
            'offset' => $offset,
            'sort_field' => $sorting['field'] ?? self::DEFAULT_ORDER,
            'sort_direction' => $sorting['direction'] ?? self::DEFAULT_DIRECTION,
            'generated_at' => time(),
            'v' => 1,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $ivBytes = random_bytes(openssl_cipher_iv_length(self::PAGE_TOKEN_ALGO));

        $encrypted = openssl_encrypt(
            $json,
            self::PAGE_TOKEN_ALGO,
            substr(self::PAGE_TOKEN_SECRET, 0, 32),
            OPENSSL_RAW_DATA,
            $ivBytes
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Page token creation failed');
        }

        $combined = $ivBytes . $encrypted;
        $encoded = $this->safeBase64Encode($combined);

        $this->logger->debug('Page token created', [
            'offset' => $offset,
            'sort' => $payload['sort_field'],
        ]);

        return self::PAGE_TOKEN_PREFIX . $encoded;
    }

    public function parsePageToken(string $token): ?array
    {
        if (str_starts_with($token, self::PAGE_TOKEN_PREFIX)) {
            $token = substr($token, strlen(self::PAGE_TOKEN_PREFIX));
        }

        $decoded = $this->safeBase64Decode($token);
        if ($decoded === false) {
            return null;
        }

        $ivLen = openssl_cipher_iv_length(self::PAGE_TOKEN_ALGO);
        $iv = substr($decoded, 0, $ivLen);
        $encrypted = substr($decoded, $ivLen);

        $json = openssl_decrypt(
            $encrypted,
            self::PAGE_TOKEN_ALGO,
            substr(self::PAGE_TOKEN_SECRET, 0, 32),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($json === false) {
            $this->logger->error('Page token decryption failed');
            return null;
        }

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!$this->isTokenFresh($payload)) {
            $this->logger->warning('Page token expired', [
                'generated_at' => $payload['generated_at'] ?? null,
                'max_age' => self::MAX_OFFSET_AGE,
            ]);
            return null;
        }

        return $payload;
    }

    private function safeBase64Encode(string $data): string
    {
        $encoded = base64_encode($data);

        if (self::URLSAFE) {
            $encoded = str_replace(['+', '/', '='], ['-', '_', ''], $encoded);
        }

        return $encoded;
    }

    private function safeBase64Decode(string $data): ?string
    {
        if (self::URLSAFE) {
            $data = str_replace(['-', '_'], ['+', '/'], $data);
        }

        return base64_decode($data, true);
    }

    private function isTokenFresh(array $payload): bool
    {
        if (!isset($payload['generated_at'])) {
            return false;
        }

        return (time() - $payload['generated_at']) <= self::MAX_OFFSET_AGE;
    }

    private function boundCount(int $count): int
    {
        return max(self::MIN_COUNT, min($count, self::MAX_COUNT));
    }

    private function normalizeSorting(array $sorting): array
    {
        return [
            'field' => $sorting['field'] ?? self::DEFAULT_ORDER,
            'direction' => $sorting['direction'] ?? self::DEFAULT_DIRECTION,
        ];
    }

    public function getDefaultCount(): int
    {
        return self::DEFAULT_COUNT;
    }

    public function getMaximumCount(): int
    {
        return self::MAX_COUNT;
    }
}
