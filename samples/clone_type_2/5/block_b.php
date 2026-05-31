<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Image;
use App\Repository\ImageRepository;
use App\Service\SearchEngine;
use Psr\Log\LoggerInterface;

final class ImageSearchService
{
    public function __construct(
        private readonly ImageRepository $imageRepository,
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
            'type' => 'image',
        ]);

        $this->logger->info('Image search performed', [
            'query' => $query,
            'results_count' => count($results),
        ]);

        return $results;
    }

    public function searchByTitle(string $title, int $limit = 10): array
    {
        $title = trim($title);

        $images = $this->imageRepository->findByTitle($title, $limit);

        $this->logger->debug('Image title search performed', [
            'title' => $title,
            'results_count' => count($images),
        ]);

        return $images;
    }

    public function searchByUploader(int $uploaderId, int $limit = 10): array
    {
        $images = $this->imageRepository->findByUploader($uploaderId, $limit);

        $this->logger->debug('Image uploader search performed', [
            'uploader_id' => $uploaderId,
            'results_count' => count($images),
        ]);

        return $images;
    }

    public function searchByDateRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 50): array
    {
        $images = $this->imageRepository->findByDateRange($from, $to, $limit);

        $this->logger->debug('Image date range search performed', [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'results_count' => count($images),
        ]);

        return $images;
    }

    public function getRelated(Image $image, int $limit = 5): array
    {
        $related = $this->imageRepository->findRelated($image, $limit);

        $this->logger->debug('Related images retrieved', [
            'image_id' => $image->getId(),
            'results_count' => count($related),
        ]);

        return $related;
    }
}
