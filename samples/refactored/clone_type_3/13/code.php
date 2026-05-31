<?php

declare(strict_types=1);

namespace App\Filter;

use Doctrine\ORM\QueryBuilder;

interface FilterInterface
{
    public function apply(QueryBuilder $qb, array $filters): QueryBuilder;
    public function applySorting(QueryBuilder $qb, ?string $sortBy, ?string $sortOrder): QueryBuilder;
    public function applyPagination(QueryBuilder $qb, int $page, int $limit): QueryBuilder;
}

abstract class AbstractFilter implements FilterInterface
{
    protected const ALLOWED_SORT_FIELDS = [];
    protected const ALLOWED_SORT_ORDERS = ['ASC', 'DESC'];

    public function apply(QueryBuilder $qb, array $filters): QueryBuilder
    {
        foreach ($this->getFilterMappings() as $filterKey => $condition) {
            if (!isset($filters[$filterKey]) || $filters[$filterKey] === '') {
                continue;
            }

            $this->applyCondition($qb, $condition, $filterKey, $filters[$filterKey]);
        }

        return $qb;
    }

    public function applySorting(QueryBuilder $qb, ?string $sortBy, ?string $sortOrder): QueryBuilder
    {
        $sortBy = $this->validateSortField($sortBy ?? $this->getDefaultSortField());
        $sortOrder = $this->validateSortOrder($sortOrder ?? $this->getDefaultSortOrder());

        $qb->orderBy($this->getSortAlias() . '.' . $sortBy, $sortOrder);

        return $qb;
    }

    public function applyPagination(QueryBuilder $qb, int $page, int $limit): QueryBuilder
    {
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
           ->setMaxResults($limit);

        return $qb;
    }

    abstract protected function getSortAlias(): string;
    abstract protected function getDefaultSortField(): string;
    abstract protected function getDefaultSortOrder(): string;

    protected function getFilterMappings(): array
    {
        return [];
    }

    protected function validateSortField(string $field): string
    {
        if (!in_array($field, static::ALLOWED_SORT_FIELDS)) {
            return $this->getDefaultSortField();
        }
        return $field;
    }

    protected function validateSortOrder(string $order): string
    {
        if (!in_array(strtoupper($order), static::ALLOWED_SORT_ORDERS)) {
            return $this->getDefaultSortOrder();
        }
        return strtoupper($order);
    }

    protected function applyCondition(QueryBuilder $qb, array $condition, string $filterKey, mixed $value): void
    {
        [$field, $operator, $transform] = $condition;

        $transformedValue = $transform !== null ? $transform($value) : $value;

        $qb->andWhere("{$this->getSortAlias()}.{$field} {$operator} :{$filterKey}")
           ->setParameter($filterKey, $transformedValue);
    }
}
