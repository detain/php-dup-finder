<?php

declare(strict_types=1);

namespace App\Services\Grpc;

use Spiral\GRPC;
use Spiral\GRPC\Exception\NotFoundException;
use Spiral\GRPC\Exception\BadRequestException;

trait GrpcServiceTrait
{
    protected function validateRequiredField(string $value, string $fieldName): void
    {
        if (empty($value)) {
            throw new BadRequestException("{$fieldName} is required");
        }
    }

    protected function validateEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestException('Invalid email format');
        }
    }

    protected function getEntityOrFail(callable $finder, int|string $id): array
    {
        $entity = $finder($id);

        if (!$entity) {
            throw new NotFoundException('Entity not found');
        }

        return $entity;
    }

    protected function buildPaginatedResponse(
        array $items,
        int $total,
        int $limit,
        int $offset,
        string $itemClass
    ): array {
        $protos = array_map(fn($item) => new $itemClass($item), $items);

        return [
            'items' => $protos,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    protected function parsePagination(GRPC\ContextInterface $ctx): array
    {
        return [
            'limit' => $ctx->getValue('limit') ?: 50,
            'offset' => $ctx->getValue('offset') ?: 0,
        ];
    }
}

class UserGrpcService
{
    use GrpcServiceTrait;

    public function GetUser(GRPC\ContextInterface $ctx, UserRequest $in): UserResponse
    {
        $this->validateRequiredField($in->getId(), 'User ID');

        $user = $this->getEntityOrFail(
            fn($id) => $this->userService->findById($id),
            $in->getId()
        );

        return new UserResponse($user);
    }
}
