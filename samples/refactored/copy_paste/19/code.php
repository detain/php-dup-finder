<?php

declare(strict_types=1);

namespace App\Services\Api;

use Illuminate\Http\JsonResponse;

final class ResponseConfig
{
    public readonly int $ok;
    public readonly int $created;
    public readonly int $noContent;
    public readonly int $badRequest;
    public readonly int $unauthorized;
    public readonly int $forbidden;
    public readonly int $notFound;
    public readonly int $serverError;

    public function __construct(
        int $ok = 200,
        int $created = 201,
        int $noContent = 204,
        int $badRequest = 400,
        int $unauthorized = 401,
        int $forbidden = 403,
        int $notFound = 404,
        int $serverError = 500
    ) {
        $this->ok = $ok;
        $this->created = $created;
        $this->noContent = $noContent;
        $this->badRequest = $badRequest;
        $this->unauthorized = $unauthorized;
        $this->forbidden = $forbidden;
        $this->notFound = $notFound;
        $this->serverError = $serverError;
    }
}

final class ApiResponseService
{
    private ResponseConfig $config;

    public function __construct(ResponseConfig $config)
    {
        $this->config = $config;
    }

    public function success(mixed $data = null, string $message = 'OK'): JsonResponse
    {
        return $this->make([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ], $this->config->ok);
    }

    public function error(string $message, int $statusCode): JsonResponse
    {
        return $this->make([
            'success' => false,
            'message' => $message,
            'timestamp' => time(),
        ], $statusCode);
    }

    public function paginated(array $data, int $total, int $page, int $perPage): JsonResponse
    {
        return $this->make([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => (int) ceil($total / $perPage),
            ],
            'timestamp' => time(),
        ], $this->config->ok);
    }

    private function make(array $body, int $statusCode): JsonResponse
    {
        return new JsonResponse($body, $statusCode);
    }
}
