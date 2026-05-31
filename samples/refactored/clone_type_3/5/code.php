<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\ApiResourceInterface;
use App\Repository\ApiResourceRepositoryInterface;
use App\Service\Serializer;
use App\Exception\ApiException;
use Psr\Log\LoggerInterface;

abstract class AbstractApiController
{
    public function __construct(
        protected readonly ApiResourceRepositoryInterface $repository,
        protected readonly Serializer $serializer,
        protected readonly LoggerInterface $logger,
    ) {}

    protected function getEntity(int $id, string $entityType): ?ApiResourceInterface
    {
        $entity = $this->repository->find($id);

        if ($entity === null) {
            throw new ApiException(ucfirst($entityType) . ' not found', 404);
        }

        return $entity;
    }

    protected function getEntitiesPaginated(
        callable $finder,
        callable $counter,
        int $page,
        int $limit
    ): array {
        $offset = ($page - 1) * $limit;

        $entities = $finder($limit, $offset);
        $total = $counter();

        return [
            'data' => $this->serializer->normalize($entities, ['list']),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    protected function validateAndCreate(array $data, callable $factory, array $validationRules): ApiResourceInterface
    {
        $errors = $this->validateData($data, $validationRules);

        if (!empty($errors)) {
            throw new ApiException('Validation failed', 422, $errors);
        }

        $entity = $factory($data);
        $this->repository->save($entity);

        $this->logger->info(ucfirst($this->getEntityType()) . ' created via API', [
            'id' => $entity->getId(),
        ]);

        return $entity;
    }

    protected function updateEntity(ApiResourceInterface $entity, array $data, array $updatableFields): ApiResourceInterface
    {
        foreach ($updatableFields as $field => $setter) {
            if (isset($data[$field])) {
                $entity->$setter($data[$field]);
            }
        }

        $this->repository->save($entity);

        $this->logger->info(ucfirst($this->getEntityType()) . ' updated via API', [
            'id' => $entity->getId(),
            'updates' => array_keys($data),
        ]);

        return $entity;
    }

    protected function validateData(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            foreach ($fieldRules as $rule) {
                $error = $this->applyRule($field, $data[$field] ?? null, $rule);
                if ($error !== null) {
                    $errors[$field] = $error;
                }
            }
        }

        return $errors;
    }

    protected function applyRule(string $field, mixed $value, array $rule): ?string
    {
        [$ruleType, $ruleParams] = $rule;

        return match ($ruleType) {
            'required' => empty($value) ? ucfirst($field) . ' is required' : null,
            'email' => !filter_var($value, FILTER_VALIDATE_EMAIL) ? 'Invalid email format' : null,
            'min' => strlen((string) $value) < $ruleParams[0] ? ucfirst($field) . ' must be at least ' . $ruleParams[0] . ' characters' : null,
            'positive' => !is_numeric($value) || $value < 0 ? ucfirst($field) . ' must be a positive number' : null,
            'unique' => $ruleParams[0]($value) !== null ? ucfirst($field) . ' already exists' : null,
            default => null,
        };
    }

    abstract protected function getEntityType(): string;
}
