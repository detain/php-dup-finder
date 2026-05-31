<?php

declare(strict_types=1);

namespace App\Api\V1\Controllers;

use App\Services\Serializer;
use Illuminate\Http\JsonResponse;

final class ApiResponseHelper
{
    private const SUCCESS_STATUS = 200;
    private const CREATED_STATUS = 201;
    private const NO_CONTENT_STATUS = 204;
    private const BAD_REQUEST_STATUS = 400;
    private const UNAUTHORIZED_STATUS = 401;
    private const FORBIDDEN_STATUS = 403;
    private const NOT_FOUND_STATUS = 404;
    private const SERVER_ERROR_STATUS = 500;

    public function success(mixed $data = null, string $message = 'Success'): JsonResponse
    {
        return $this->buildResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => $this->currentTimestamp(),
        ], self::SUCCESS_STATUS);
    }

    public function created(mixed $data = null, string $message = 'Resource created'): JsonResponse
    {
        return $this->buildResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => $this->currentTimestamp(),
        ], self::CREATED_STATUS);
    }

    public function noContent(string $message = 'No content'): JsonResponse
    {
        return $this->buildResponse([
            'success' => true,
            'message' => $message,
            'data' => null,
            'timestamp' => $this->currentTimestamp(),
        ], self::NO_CONTENT_STATUS);
    }

    public function error(string $message, int $statusCode = self::BAD_REQUEST_STATUS): JsonResponse
    {
        return $this->buildResponse([
            'success' => false,
            'message' => $message,
            'data' => null,
            'timestamp' => $this->currentTimestamp(),
        ], $statusCode);
    }

    public function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->error($message, self::NOT_FOUND_STATUS);
    }

    public function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->error($message, self::UNAUTHORIZED_STATUS);
    }

    public function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return $this->error($message, self::FORBIDDEN_STATUS);
    }

    public function serverError(string $message = 'Internal server error'): JsonResponse
    {
        return $this->error($message, self::SERVER_ERROR_STATUS);
    }

    public function validationError(array $errors, string $message = 'Validation failed'): JsonResponse
    {
        return $this->buildResponse([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'timestamp' => $this->currentTimestamp(),
        ], self::BAD_REQUEST_STATUS);
    }

    public function paginated(
        array $data,
        int $total,
        int $page,
        int $perPage,
        string $message = 'Success'
    ): JsonResponse {
        return $this->buildResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => (int) ceil($total / $perPage),
                'has_next' => $page * $perPage < $total,
                'has_previous' => $page > 1,
            ],
            'timestamp' => $this->currentTimestamp(),
        ], self::SUCCESS_STATUS);
    }

    public function withMeta(array $data, array $metadata, string $message = 'Success'): JsonResponse
    {
        return $this->buildResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $metadata,
            'timestamp' => $this->currentTimestamp(),
        ], self::SUCCESS_STATUS);
    }

    public function withIncludes(array $data, array $includes, string $message = 'Success'): JsonResponse
    {
        return $this->buildResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'included' => $includes,
            'timestamp' => $this->currentTimestamp(),
        ], self::SUCCESS_STATUS);
    }

    public function withLinks(array $data, array $links, string $message = 'Success'): JsonResponse
    {
        return $this->buildResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'links' => $links,
            'timestamp' => $this->currentTimestamp(),
        ], self::SUCCESS_STATUS);
    }

    private function buildResponse(array $body, int $statusCode): JsonResponse
    {
        return new JsonResponse($body, $statusCode);
    }

    private function currentTimestamp(): int
    {
        return time();
    }
}
