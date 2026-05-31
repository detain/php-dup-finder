<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JsonResponseBuilder
{
    public const HTTP_OK = 200;
    public const HTTP_CREATED = 201;
    public const HTTP_ACCEPTED = 202;
    public const HTTP_NO_CONTENT = 204;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_INTERNAL_ERROR = 500;

    public function respond(mixed $payload = null, int $code = self::HTTP_OK): JsonResponse
    {
        return $this->construct([
            'ok' => $code >= 200 && $code < 300,
            'result' => $payload,
            'ts' => $this->timestamp(),
        ], $code);
    }

    public function respondSuccess(mixed $result = null, string $statusMessage = 'OK'): JsonResponse
    {
        return $this->construct([
            'ok' => true,
            'result' => $result,
            'status' => $statusMessage,
            'ts' => $this->timestamp(),
        ], self::HTTP_OK);
    }

    public function respondCreated(mixed $result = null, string $statusMessage = 'Created'): JsonResponse
    {
        return $this->construct([
            'ok' => true,
            'result' => $result,
            'status' => $statusMessage,
            'ts' => $this->timestamp(),
        ], self::HTTP_CREATED);
    }

    public function respondNoContent(string $statusMessage = 'No Content'): JsonResponse
    {
        return $this->construct([
            'ok' => true,
            'result' => null,
            'status' => $statusMessage,
            'ts' => $this->timestamp(),
        ], self::HTTP_NO_CONTENT);
    }

    public function respondError(string $errorMessage, int $httpCode = self::HTTP_BAD_REQUEST): JsonResponse
    {
        return $this->construct([
            'ok' => false,
            'error' => $errorMessage,
            'ts' => $this->timestamp(),
        ], $httpCode);
    }

    public function respondNotFound(string $message = 'Not Found'): JsonResponse
    {
        return $this->respondError($message, self::HTTP_NOT_FOUND);
    }

    public function respondUnauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->respondError($message, self::HTTP_UNAUTHORIZED);
    }

    public function respondForbidden(string $message = 'Forbidden'): JsonResponse
    {
        return $this->respondError($message, self::HTTP_FORBIDDEN);
    }

    public function respondServerError(string $message = 'Internal Server Error'): JsonResponse
    {
        return $this->respondError($message, self::HTTP_INTERNAL_ERROR);
    }

    public function respondValidation(array $validationErrors, string $message = 'Validation Error'): JsonResponse
    {
        return $this->construct([
            'ok' => false,
            'status' => $message,
            'errors' => $validationErrors,
            'ts' => $this->timestamp(),
        ], self::HTTP_BAD_REQUEST);
    }

    public function respondCollection(
        array $items,
        int $count,
        int $page,
        int $pageSize,
        string $message = 'OK'
    ): JsonResponse {
        return $this->construct([
            'ok' => true,
            'status' => $message,
            'result' => $items,
            'meta' => [
                'total_count' => $count,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => (int) ceil($count / $pageSize),
                'has_next' => ($page * $pageSize) < $count,
                'has_prev' => $page > 1,
            ],
            'ts' => $this->timestamp(),
        ], self::HTTP_OK);
    }

    public function respondWithContext(array $items, array $context, string $message = 'OK'): JsonResponse
    {
        return $this->construct([
            'ok' => true,
            'status' => $message,
            'result' => $items,
            'context' => $context,
            'ts' => $this->timestamp(),
        ], self::HTTP_OK);
    }

    public function respondWithAttachments(array $items, array $attachments, string $message = 'OK'): JsonResponse
    {
        return $this->construct([
            'ok' => true,
            'status' => $message,
            'result' => $items,
            'attachments' => $attachments,
            'ts' => $this->timestamp(),
        ], self::HTTP_OK);
    }

    private function construct(array $body, int $statusCode): JsonResponse
    {
        return new JsonResponse($body, $statusCode);
    }

    private function timestamp(): int
    {
        return time();
    }
}
