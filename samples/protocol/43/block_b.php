<?php

declare(strict_types=1);

namespace App\Services\Pagination;

use Psr\Log\LoggerInterface;
use JsonSerializable;

final class TokenBasedPaginationService
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const MIN_LIMIT = 1;
    private const TOKEN_ENCRYPTION_KEY = 'your-256-bit-secret-key-here-must-be-32-chars';
    private const TOKEN_CIPHER = 'AES-256-CBC';
    private const TOKEN_PREFIX = 'tk_';
    private const TOKEN_EXPIRY = 3600;
    private const URLSAFE_ENCODING = true;
    private const RETURN_TOTAL_RECORDS = true;
    private const RETURN_NEXT_TOKEN = true;
    private const RETURN_PREV_TOKEN = false;
    private const DEFAULT_ORDER_BY = 'id';
    private const DEFAULT_ORDER_DIR = 'asc';
    private const MAX_TOKEN_AGE_SECONDS = 86400;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function paginateWithToken(
        callable $dataProvider,
        ?string $pageToken = null,
        ?int $limit = null,
        array $options = []
    ): array {
        $limit = $this->clampLimit($limit ?? self::DEFAULT_LIMIT);
        $options = $this->normalizeOptions($options);

        $decodedToken = null;

        if ($pageToken !== null) {
            $decodedToken = $this->decodePageToken($pageToken);
            if ($decodedToken === null) {
                throw new \InvalidArgumentException('Invalid or expired page token');
            }
        }

        $fetchOptions = array_merge($options, [
            'limit' => $limit + 1,
            'starting_after' => $decodedToken,
        ]);

        $result = $dataProvider($fetchOptions);

        $items = $result['data'] ?? $result;
        $totalCount = $result['total'] ?? null;

        $hasMore = count($items) > $limit;
        $paginatedItems = array_slice($items, 0, $limit);

        $nextPageToken = null;
        if ($hasMore && !empty($paginatedItems)) {
            $lastRecord = end($paginatedItems);
            $nextPageToken = $this->generatePageToken($lastRecord, $options);
        }

        $response = [
            'items' => $paginatedItems,
            'has_more' => $hasMore,
        ];

        if (self::RETURN_TOTAL_RECORDS && $totalCount !== null) {
            $response['total'] = $totalCount;
        }

        if (self::RETURN_NEXT_TOKEN && $nextPageToken !== null) {
            $response['next_page_token'] = $nextPageToken;
        }

        if (self::RETURN_PREV_TOKEN && $decodedToken !== null) {
            $response['has_previous_page'] = true;
        }

        return $response;
    }

    public function generatePageToken(array $record, array $options = []): string
    {
        $orderBy = $options['order_by'] ?? self::DEFAULT_ORDER_BY;
        $orderDir = $options['order_dir'] ?? self::DEFAULT_ORDER_DIR;

        $tokenPayload = [
            'order_by' => $orderBy,
            'order_dir' => $orderDir,
            'value' => $record[$orderBy] ?? null,
            'id' => $record['id'] ?? null,
            'timestamp' => time(),
            'version' => 1,
        ];

        $encrypted = $this->encryptPayload($tokenPayload);
        $encoded = $this->base64UrlEncode($encrypted);

        $this->logger->debug('Page token generated', [
            'order_by' => $orderBy,
            'value' => $tokenPayload['value'],
        ]);

        return self::TOKEN_PREFIX . $encoded;
    }

    public function decodePageToken(string $token): ?array
    {
        if (str_starts_with($token, self::TOKEN_PREFIX)) {
            $token = substr($token, strlen(self::TOKEN_PREFIX));
        }

        $decoded = $this->base64UrlDecode($token);
        if ($decoded === null) {
            return null;
        }

        $payload = $this->decryptPayload($decoded);
        if ($payload === null) {
            return null;
        }

        if (!$this->isTokenValid($payload)) {
            $this->logger->warning('Page token validation failed', [
                'timestamp' => $payload['timestamp'] ?? null,
                'max_age' => self::MAX_TOKEN_AGE_SECONDS,
            ]);
            return null;
        }

        return $payload;
    }

    private function encryptPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $iv = random_bytes(openssl_cipher_iv_length(self::TOKEN_CIPHER));

        $encrypted = openssl_encrypt(
            $json,
            self::TOKEN_CIPHER,
            substr(self::TOKEN_ENCRYPTION_KEY, 0, 32),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Token encryption failed');
        }

        return $iv . $encrypted;
    }

    private function decryptPayload(string $data): ?array
    {
        $ivLength = openssl_cipher_iv_length(self::TOKEN_CIPHER);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);

        $decrypted = openssl_decrypt(
            $encrypted,
            self::TOKEN_CIPHER,
            substr(self::TOKEN_ENCRYPTION_KEY, 0, 32),
            OPENSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            return null;
        }

        return json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
    }

    private function base64UrlEncode(string $data): string
    {
        $encoded = base64_encode($data);

        if (self::URLSAFE_ENCODING) {
            $encoded = str_replace(['+', '/', '='], ['-', '_', ''], $encoded);
        }

        return $encoded;
    }

    private function base64UrlDecode(string $data): ?string
    {
        if (self::URLSAFE_ENCODING) {
            $data = str_replace(['-', '_'], ['+', '/'], $data);
        }

        return base64_decode($data, true);
    }

    private function isTokenValid(array $payload): bool
    {
        if (!isset($payload['timestamp'])) {
            return false;
        }

        $age = time() - $payload['timestamp'];

        return $age <= self::MAX_TOKEN_AGE_SECONDS;
    }

    private function clampLimit(int $limit): int
    {
        return max(self::MIN_LIMIT, min($limit, self::MAX_LIMIT));
    }

    private function normalizeOptions(array $options): array
    {
        return [
            'order_by' => $options['order_by'] ?? self::DEFAULT_ORDER_BY,
            'order_dir' => $options['order_dir'] ?? self::DEFAULT_ORDER_DIR,
        ];
    }

    public function getDefaultLimit(): int
    {
        return self::DEFAULT_LIMIT;
    }

    public function getMaximumLimit(): int
    {
        return self::MAX_LIMIT;
    }
}
