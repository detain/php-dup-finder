<?php

declare(strict_types=1);

namespace App\Services\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResponseEnvelope
{
    private const STATUS_OK = 200;
    private const STATUS_CREATED = 201;
    private const STATUS_NO_CONTENT = 204;
    private const STATUS_BAD_REQUEST = 400;
    private const STATUS_UNAUTHORIZED = 401;
    private const STATUS_FORBIDDEN = 403;
    private const STATUS_NOT_FOUND = 404;
    private const STATUS_INTERNAL = 500;

    public function success(mixed $result = null, string $msg = 'Success'): JsonResponse
    {
        return $this->wrap([
            'success' => true,
            'message' => $msg,
            'payload' => $result,
            'generated_at' => time(),
        ], self::STATUS_OK);
    }

    public function created(mixed $result = null, string $msg = 'Created'): JsonResponse
    {
        return $this->wrap([
            'success' => true,
            'message' => $msg,
            'payload' => $result,
            'generated_at' => time(),
        ], self::STATUS_CREATED);
    }

    public function noContent(string $msg = 'No Content'): JsonResponse
    {
        return $this->wrap([
            'success' => true,
            'message' => $msg,
            'payload' => null,
            'generated_at' => time(),
        ], self::STATUS_NO_CONTENT);
    }

    public function failure(string $reason, int $code = self::STATUS_BAD_REQUEST): JsonResponse
    {
        return $this->wrap([
            'success' => false,
            'message' => $reason,
            'payload' => null,
            'generated_at' => time(),
        ], $code);
    }

    public function notFound(string $msg = 'Not Found'): JsonResponse
    {
        return $this->failure($msg, self::STATUS_NOT_FOUND);
    }

    public function unauthorized(string $msg = 'Unauthorized'): JsonResponse
    {
        return $this->failure($msg, self::STATUS_UNAUTHORIZED);
    }

    public function forbidden(string $msg = 'Forbidden'): JsonResponse
    {
        return $this->failure($msg, self::STATUS_FORBIDDEN);
    }

    public function serverError(string $msg = 'Internal Server Error'): JsonResponse
    {
        return $this->failure($msg, self::STATUS_INTERNAL);
    }

    public function invalidInput(array $fieldErrors, string $msg = 'Invalid Input'): JsonResponse
    {
        return $this->wrap([
            'success' => false,
            'message' => $msg,
            'field_errors' => $fieldErrors,
            'generated_at' => time(),
        ], self::STATUS_BAD_REQUEST);
    }

    public function paginatedResult(
        array $records,
        int $totalRecords,
        int $currentPage,
        int $pageSize,
        string $msg = 'Success'
    ): JsonResponse {
        $totalPages = $currentPage > 0 ? (int) ceil($totalRecords / $currentPage) : 0;

        return $this->wrap([
            'success' => true,
            'message' => $msg,
            'payload' => $records,
            'paging' => [
                'total' => $totalRecords,
                'per_page' => $pageSize,
                'current' => $currentPage,
                'total_pages' => $totalPages,
                'has_more' => ($currentPage * $pageSize) < $totalRecords,
                'has_less' => $currentPage > 1,
            ],
            'generated_at' => time(),
        ], self::STATUS_OK);
    }

    public function withHeaders(array $records, array $headers, string $msg = 'Success'): JsonResponse
    {
        return $this->wrap([
            'success' => true,
            'message' => $msg,
            'payload' => $records,
            'headers' => $headers,
            'generated_at' => time(),
        ], self::STATUS_OK);
    }

    public function withRelationships(array $records, array $relationships, string $msg = 'Success'): JsonResponse
    {
        return $this->wrap([
            'success' => true,
            'message' => $msg,
            'payload' => $records,
            'relationships' => $relationships,
            'generated_at' => time(),
        ], self::STATUS_OK);
    }

    private function wrap(array $body, int $httpCode): JsonResponse
    {
        return new JsonResponse($body, $httpCode);
    }
}
