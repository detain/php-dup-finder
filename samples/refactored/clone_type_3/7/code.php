<?php

declare(strict_types=1);

namespace App\Transform;

use App\Entity\EntityInterface;

interface MapperInterface
{
    public function toArray(EntityInterface $entity): array;
    public function toSummaryArray(EntityInterface $entity): array;
    public function toCsvRow(EntityInterface $entity): array;
    public function toFlatArray(EntityInterface $entity): array;
}

abstract class AbstractMapper implements MapperInterface
{
    public function toArray(EntityInterface $entity): array
    {
        return $this->mapToArray($entity);
    }

    public function toSummaryArray(EntityInterface $entity): array
    {
        return $this->mapToSummaryArray($entity);
    }

    public function toCsvRow(EntityInterface $entity): array
    {
        return $this->mapToCsvRow($entity);
    }

    public function toFlatArray(EntityInterface $entity): array
    {
        return $this->mapToFlatArray($entity);
    }

    abstract protected function mapToArray(EntityInterface $entity): array;
    abstract protected function mapToSummaryArray(EntityInterface $entity): array;
    abstract protected function mapToCsvRow(EntityInterface $entity): array;
    abstract protected function mapToFlatArray(EntityInterface $entity): array;

    protected function formatPrice(float $price): string
    {
        return number_format($price, 2);
    }

    protected function formatDate(\DateTimeInterface $date): string
    {
        return $date->format('c');
    }

    protected function formatDateForCsv(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
