<?php

declare(strict_types=1);

namespace App\Http\Client;

use Psr\Log\LoggerInterface;
use JsonSerializable;

final class CursorPaginationClient
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;
    private const MIN_PAGE_SIZE = 1;
    private const CURSOR_SECRET = 'your-256-bit-secret-key-here';
    private const CURSOR_ALGORITHM = 'AES-256-CBC';
    private const CURSOR_TTL = 3600;
    private const CURSOR_PREFIX = 'cursor_';
    private const ENCODE_URL_SAFE = true;
    private const INCLUDE_TOTAL_COUNT = true;
    private const INCLUDE_HAS_NEXT = true;
    private const INCLUDE_HAS_PREV = false;
    private const DEFAULT_SORT_FIELD = 'created_at';
    private const DEFAULT_SORT_DIRECTION = 'desc';
    private const MAX_CURSOR_AGE = 86400;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function paginate(
        callable $dataFetcher,
        ?string $cursor = null,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        array $sortParams = []
    ): array {
        $pageSize = $this->constrainPageSize($pageSize);
        $sortParams = $this->normalizeSortParams($sortParams);

        $decodedCursor = null;
        if ($cursor !== null) {
            $decodedCursor = $this->decodeCursor($cursor);
            if ($decodedCursor === null) {
                throw new \InvalidArgumentException('Invalid cursor provided');
            }
        }

        $fetchParams = [
            'limit' => $pageSize + 1,
            'cursor' => $decodedCursor,
            'sort' => $sortParams,
        ];

        $data = $dataFetcher($fetchParams);

        $hasNext = count($data) > $pageSize;
        $items = array_slice($data, 0, $pageSize);

        $nextCursor = null;
        if ($hasNext && !empty($items)) {
            $lastItem = end($items);
            $nextCursor = $this->createCursor($lastItem, $sortParams);
        }

        $result = [
            'data' => $items,
            'pagination' => [
                'has_next' => $hasNext,
                'has_previous' => $decodedCursor !== null,
                'page_size' => $pageSize,
            ],
        ];

        if (self::INCLUDE_TOTAL_COUNT && isset($data['total_count'])) {
            $result['pagination']['total_count'] = $data['total_count'];
        }

        if ($nextCursor !== null) {
            $result['pagination']['next_cursor'] = $nextCursor;
        }

        if (self::INCLUDE_HAS_PREV && $decodedCursor !== null) {
            $result['pagination']['has_previous'] = true;
        }

        return $result;
    }

    public function createCursor(mixed $item, array $sortParams = []): string
    {
        $cursorData = [
            'sort_field' => $sortParams['field'] ?? self::DEFAULT_SORT_FIELD,
            'sort_direction' => $sortParams['direction'] ?? self::DEFAULT_SORT_DIRECTION,
            'sort_value' => $this->extractSortValue($item, $sortParams['field'] ?? self::DEFAULT_SORT_FIELD),
            'item_id' => $item['id'] ?? null,
            'created_at' => time(),
        ];

        $encoded = $this->encodeCursorData($cursorData);

        $this->logger->debug('Cursor created', [
            'sort_field' => $cursorData['sort_field'],
            'sort_value' => $cursorData['sort_value'],
        ]);

        return $encoded;
    }

    public function decodeCursor(string $cursor): ?array
    {
        try {
            $decoded = $this->decodeCursorData($cursor);

            if ($decoded === null) {
                return null;
            }

            if (!$this->isCursorValid($decoded)) {
                $this->logger->warning('Cursor validation failed', [
                    'created_at' => $decoded['created_at'] ?? null,
                    'max_age' => self::MAX_CURSOR_AGE,
                ]);
                return null;
            }

            return $decoded;
        } catch (\Exception $e) {
            $this->logger->error('Failed to decode cursor', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function encodeCursorData(array $data): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        $iv = random_bytes(openssl_cipher_iv_length(self::CURSOR_ALGORITHM));

        $encrypted = openssl_encrypt(
            $json,
            self::CURSOR_ALGORITHM,
            substr(self::CURSOR_SECRET, 0, 32),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Failed to encrypt cursor data');
        }

        $combined = $iv . $encrypted;
        $encoded = base64_encode($combined);

        if (self::ENCODE_URL_SAFE) {
            $encoded = str_replace(['+', '/', '='], ['-', '_', ''], $encoded);
        }

        return self::CURSOR_PREFIX . $encoded;
    }

    public function decodeCursorData(string $cursor): ?array
    {
        if (str_starts_with($cursor, self::CURSOR_PREFIX)) {
            $cursor = substr($cursor, strlen(self::CURSOR_PREFIX));
        }

        if (self::ENCODE_URL_SAFE) {
            $cursor = str_replace(['-', '_'], ['+', '/'], $cursor);
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length(self::CURSOR_ALGORITHM);
        $iv = substr($decoded, 0, $ivLength);
        $encrypted = substr($decoded, $ivLength);

        $json = openssl_decrypt(
            $encrypted,
            self::CURSOR_ALGORITHM,
            substr(self::CURSOR_SECRET, 0, 32),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($json === false) {
            return null;
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function isCursorValid(array $cursorData): bool
    {
        if (!isset($cursorData['created_at'])) {
            return false;
        }

        $age = time() - $cursorData['created_at'];

        return $age <= self::MAX_CURSOR_AGE;
    }

    private function extractSortValue(mixed $item, string $sortField): mixed
    {
        $fields = explode('.', $sortField);
        $value = $item;

        foreach ($fields as $field) {
            if (!is_array($value) || !isset($value[$field])) {
                return null;
            }
            $value = $value[$field];
        }

        return $value;
    }

    private function constrainPageSize(int $pageSize): int
    {
        return max(self::MIN_PAGE_SIZE, min($pageSize, self::MAX_PAGE_SIZE));
    }

    private function normalizeSortParams(array $sortParams): array
    {
        return [
            'field' => $sortParams['field'] ?? self::DEFAULT_SORT_FIELD,
            'direction' => $sortParams['direction'] ?? self::DEFAULT_SORT_DIRECTION,
        ];
    }

    public function getDefaultPageSize(): int
    {
        return self::DEFAULT_PAGE_SIZE;
    }

    public function getMaxPageSize(): int
    {
        return self::MAX_PAGE_SIZE;
    }
}
