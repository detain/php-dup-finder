<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\SearchableInterface;
use App\Repository\SearchableRepositoryInterface;
use App\Service\SearchEngine;
use Psr\Log\LoggerInterface;

interface SearchServiceInterface
{
    public function search(string $query, int $limit = 20): array;
    public function searchByTitle(string $title, int $limit = 10): array;
    public function searchByEntityField(string $field, mixed $value, int $limit = 10): array;
    public function searchByDateRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 50): array;
    public function getRelated(SearchableInterface $entity, int $limit = 5): array;
}

abstract class AbstractSearchService implements SearchServiceInterface
{
    public function __construct(
        protected readonly SearchableRepositoryInterface $repository,
        protected readonly SearchEngine $searchEngine,
        protected readonly LoggerInterface $logger,
    ) {}

    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);

        if (strlen($query) < 2) {
            return [];
        }

        $results = $this->searchEngine->search($query, [
            'limit' => $limit,
            'type' => $this->getSearchType(),
        ]);

        $this->logger->info('Search performed', [
            'query' => $query,
            'results_count' => count($results),
        ]);

        return $results;
    }

    public function searchByTitle(string $title, int $limit = 10): array
    {
        $title = trim($title);

        $entities = $this->repository->findByTitle($title, $limit);

        $this->logger->debug('Title search performed', [
            'title' => $title,
            'results_count' => count($entities),
        ]);

        return $entities;
    }

    public function searchByEntityField(string $field, mixed $value, int $limit = 10): array
    {
        $entities = $this->repository->findByField($field, $value, $limit);

        $this->logger->debug('Entity field search performed', [
            'field' => $field,
            'value' => $value,
            'results_count' => count($entities),
        ]);

        return $entities;
    }

    public function searchByDateRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 50): array
    {
        $entities = $this->repository->findByDateRange($from, $to, $limit);

        $this->logger->debug('Date range search performed', [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'results_count' => count($entities),
        ]);

        return $entities;
    }

    public function getRelated(SearchableInterface $entity, int $limit = 5): array
    {
        $related = $this->repository->findRelated($entity, $limit);

        $this->logger->debug('Related entities retrieved', [
            'entity_id' => $entity->getId(),
            'results_count' => count($related),
        ]);

        return $related;
    }

    abstract protected function getSearchType(): string;
}
