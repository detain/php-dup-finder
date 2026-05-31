<?php
declare(strict_types=1);

namespace App\Core\Persistence\Doctrine\Hydrator;

use Doctrine\DBAL\Result;
use App\Core\Factory\FactoryInterface;

interface HydrationStrategy
{
    public function getDateFormat(): string;
    public function shouldHydrateField(string $field): bool;
    public function transformValue(string $field, mixed $value): mixed;
}

abstract class AbstractHydrator
{
    protected function hydrateOne(Result $result, HydrationStrategy $strategy): ?object
    {
        $row = $result->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->hydrateRow($row, $strategy);
    }

    protected function hydrateAll(Result $result, HydrationStrategy $strategy): array
    {
        $entities = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $entities[] = $this->hydrateRow($row, $strategy);
        }

        return $entities;
    }

    protected function hydrateRow(array $row, HydrationStrategy $strategy): object
    {
        $entity = $this->factory->create();
        $dateFormat = $strategy->getDateFormat();

        foreach ($row as $field => $value) {
            if (!$strategy->shouldHydrateField($field)) {
                continue;
            }

            $transformed = $strategy->transformValue($field, $value);
            $setter = $this->getSetterForField($field);

            if ($setter !== null && method_exists($entity, $setter)) {
                $entity->{$setter}($this->parseValue($transformed, $field, $dateFormat));
            }
        }

        return $entity;
    }

    private function getSetterForField(string $field): ?string
    {
        $camelCase = str_replace('_', '', ucwords($field, '_'));
        return 'set' . $camelCase;
    }

    private function parseValue(mixed $value, string $field, string $dateFormat): mixed
    {
        if ($value === null) {
            return null;
        }

        if (str_ends_with($field, '_at') || str_ends_with($field, '_date')) {
            return new \DateTimeImmutable($value);
        }

        return $value;
    }
}

final class UserHydrator extends AbstractHydrator {}
