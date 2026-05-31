<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Service\SearchEngine;
use Psr\Log\LoggerInterface;

final class DocumentSearchService
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly SearchEngine $searchEngine,
        private readonly LoggerInterface $logger,
    ) {}

    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);

        if (strlen($query) < 2) {
            return [];
        }

        $results = $this->searchEngine->search($query, [
            'limit' => $limit,
            'type' => 'document',
        ]);

        $this->logger->info('Document search performed', [
            'query' => $query,
            'results_count' => count($results),
        ]);

        return $results;
    }

    public function searchByTitle(string $title, int $limit = 10): array
    {
        $title = trim($title);

        $documents = $this->documentRepository->findByTitle($title, $limit);

        $this->logger->debug('Document title search performed', [
            'title' => $title,
            'results_count' => count($documents),
        ]);

        return $documents;
    }

    public function searchByAuthor(int $authorId, int $limit = 10): array
    {
        $documents = $this->documentRepository->findByAuthor($authorId, $limit);

        $this->logger->debug('Document author search performed', [
            'author_id' => $authorId,
            'results_count' => count($documents),
        ]);

        return $documents;
    }

    public function searchByDateRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 50): array
    {
        $documents = $this->documentRepository->findByDateRange($from, $to, $limit);

        $this->logger->debug('Document date range search performed', [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'results_count' => count($documents),
        ]);

        return $documents;
    }

    public function getRelated(Document $document, int $limit = 5): array
    {
        $related = $this->documentRepository->findRelated($document, $limit);

        $this->logger->debug('Related documents retrieved', [
            'document_id' => $document->getId(),
            'results_count' => count($related),
        ]);

        return $related;
    }
}
