<?php

declare(strict_types=1);

namespace App\Api\GraphQL;

use GraphQL\GraphQL;
use GraphQL\Schema\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\MaxDepth;
use GraphQL\Validator\Rules\DisableIntrospection;

trait GraphQLHandlerTrait
{
    protected AuthenticationService $authService;
    protected RateLimiter $rateLimiter;

    protected function handleGraphQLRequest(string $query, ?string $operationName, array $variables): array
    {
        $this->checkRateLimit();
        $user = $this->authenticate();
        $validationRules = $this->buildValidationRules();
        $result = $this->executeGraphQLQuery($query, $operationName, $variables, $user, $validationRules);

        return [
            'data' => $result->toArray(),
            'errors' => $this->formatErrors($result->getErrors()),
        ];
    }

    protected function checkRateLimit(): void
    {
        $clientId = $this->authService->getClientId() ?? $this->getClientIp();
        if (!$this->rateLimiter->attempt($clientId, 100, 60)) {
            throw new RateLimitExceededException('Rate limit exceeded');
        }
    }

    protected function authenticate(): ?array
    {
        $token = $this->getAuthToken();
        if (!$token) {
            return null;
        }

        $user = $this->authService->validateToken($token);
        if (!$user) {
            throw new AuthenticationException('Invalid token');
        }

        return $user;
    }

    protected function buildValidationRules(): array
    {
        $rules = [
            new QueryComplexity(100),
            new MaxDepth(10),
        ];

        if (!$this->authService->isAdmin()) {
            $rules[] = new DisableIntrospection();
        }

        return DocumentValidator::createDefaultSchema($rules);
    }

    protected function executeGraphQLQuery(
        string $query,
        ?string $operationName,
        array $variables,
        ?array $user,
        array $validationRules
    ): \GraphQL\Execution\Result {
        return GraphQL::executeQuery(
            $this->schema,
            $query,
            null,
            ['user' => $user],
            $variables,
            $operationName,
            null,
            $validationRules
        );
    }

    protected function getAuthToken(): ?string
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }

    protected function formatErrors(array $errors): array
    {
        return array_map(fn($e) => [
            'message' => $e->getMessage(),
            'locations' => $e->getLocations(),
            'path' => $e->getPath(),
        ], $errors);
    }
}

class GraphQLHandler
{
    use GraphQLHandlerTrait;

    public function handle(string $query, ?string $operationName, array $variables): array
    {
        return $this->handleGraphQLRequest($query, $operationName, $variables);
    }
}
