<?php

declare(strict_types=1);

namespace App\Hydrator;

use App\Entity\HydratableInterface;

interface HydratorInterface
{
    public function hydrateFromArray(HydratableInterface $entity, array $data): HydratableInterface;
    public function extractToArray(HydratableInterface $entity): array;
}

abstract class AbstractHydrator implements HydratorInterface
{
    public function hydrateFromArray(HydratableInterface $entity, array $data): HydratableInterface
    {
        foreach ($this->getHydratableFields() as $field => $setter) {
            if (isset($data[$field])) {
                $entity->$setter($this->transformValue($field, $data[$field]));
            }
        }

        return $entity;
    }

    public function extractToArray(HydratableInterface $entity): array
    {
        $data = [];

        foreach ($this->getExtractableFields() as $field => $getter) {
            $data[$field] = $entity->$getter();
        }

        return $data;
    }

    protected function getHydratableFields(): array
    {
        return [];
    }

    protected function getExtractableFields(): array
    {
        return [];
    }

    protected function transformValue(string $field, mixed $value): mixed
    {
        return $value;
    }
}
