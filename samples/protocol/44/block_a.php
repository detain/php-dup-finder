<?php

declare(strict_types=1);

namespace App\Api\GraphQL;

use GraphQL\GraphQL;
use GraphQL\Schema\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\MaxDepth;
use GraphQL\Validator\Rules\DisableIntrospection;
use App\Api\GraphQL\Resolvers\UserResolver;
use App\Api\GraphQL\Resolvers\ProductResolver;
use App\Api\GraphQL\Resolvers\OrderResolver;
use App\Services\AuthenticationService;
use App\Services\RateLimiter;
use App\Exceptions\AuthenticationException;
use App\Exceptions\RateLimitExceededException;

class GraphQLHandler
{
    private Schema $schema;
    private AuthenticationService $authService;
    private RateLimiter $rateLimiter;
    private array $resolvers;

    public function __construct(
        AuthenticationService $authService,
        RateLimiter $rateLimiter
    ) {
        $this->authService = $authService;
        $this->rateLimiter = $rateLimiter;
        $this->resolvers = [
            'user' => new UserResolver(),
            'product' => new ProductResolver(),
            'order' => new OrderResolver(),
        ];
        $this->schema = $this->buildSchema();
    }

    private function buildSchema(): Schema
    {
        $queryType = new \GraphQL\Type\Definition\ObjectType([
            'name' => 'Query',
            'fields' => [
                'user' => [
                    'type' => \GraphQL\Type\Definition\Type::string(),
                    'args' => [
                        'id' => ['type' => \GraphQL\Type\Definition\Type::nonNull(\GraphQL\Type\Definition\Type::id())],
                    ],
                    'resolve' => [$this->resolvers['user'], 'resolveUser'],
                ],
                'users' => [
                    'type' => \GraphQL\Type\Definition\Type::listOf(\GraphQL\Type\Definition\Type::string()),
                    'args' => [
                        'limit' => ['type' => \GraphQL\Type\Definition\Type::int()],
                        'offset' => ['type' => \GraphQL\Type\Definition\Type::int()],
                    ],
                    'resolve' => [$this->resolvers['user'], 'resolveUsers'],
                ],
            ],
        ]);

        return new Schema(['query' => $queryType]);
    }

    public function handle(string $query, ?string $operationName = null, array $variables = []): array
    {
        // Rate limiting
        $clientId = $this->authService->getClientId() ?? $this->getClientIp();
        if (!$this->rateLimiter->attempt($clientId, 100, 60)) {
            throw new RateLimitExceededException('Rate limit exceeded for GraphQL operations');
        }

        // Authentication check
        $token = $this->getAuthToken();
        if ($token) {
            $user = $this->authService->validateToken($token);
            if (!$user) {
                throw new AuthenticationException('Invalid or expired authentication token');
            }
        }

        // Validation rules
        $rules = [
            new QueryComplexity(100),
            new MaxDepth(10),
        ];

        if (!$this->authService->isAdmin()) {
            $rules[] = new DisableIntrospection();
        }

        $schemaValidationRules = DocumentValidator::createDefaultSchema($rules);

        // Execute query
        $result = GraphQL::executeQuery(
            $this->schema,
            $query,
            null,
            ['user' => $user ?? null],
            $variables,
            $operationName,
            null,
            $schemaValidationRules
        );

        return [
            'data' => $result->toArray(),
            'errors' => $this->formatErrors($result->getErrors()),
        ];
    }

    private function getAuthToken(): ?string
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }

    private function getClientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function formatErrors(array $errors): array
    {
        return array_map(function ($error) {
            return [
                'message' => $error->getMessage(),
                'locations' => $error->getLocations(),
                'path' => $error->getPath(),
            ];
        }, $errors);
    }
}
