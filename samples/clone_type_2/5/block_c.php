<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Service\SearchEngine;
use Psr\Log\LoggerInterface;

final class VideoSearchService
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
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
            'type' => 'video',
        ]);

        $this->logger->info('Video search performed', [
            'query' => $query,
            'results_count' => count($results),
        ]);

        return $results;
    }

    public function searchByTitle(string $title, int $limit = 10): array
    {
        $title = trim($title);

        $videos = $this->videoRepository->findByTitle($title, $limit);

        $this->logger->debug('Video title search performed', [
            'title' => $title,
            'results_count' => count($videos),
        ]);

        return $videos;
    }

    public function searchByCreator(int $creatorId, int $limit = 10): array
    {
        $videos = $this->videoRepository->findByCreator($creatorId, $limit);

        $this->logger->debug('Video creator search performed', [
            'creator_id' => $creatorId,
            'results_count' => count($videos),
        ]);

        return $videos;
    }

    public function searchByDateRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 50): array
    {
        $videos = $this->videoRepository->findByDateRange($from, $to, $limit);

        $this->logger->debug('Video date range search performed', [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'results_count' => count($videos),
        ]);

        return $videos;
    }

    public function getRelated(Video $video, int $limit = 5): array
    {
        $related = $this->videoRepository->findRelated($video, $limit);

        $this->logger->debug('Related videos retrieved', [
            'video_id' => $video->getId(),
            'results_count' => count($related),
        ]);

        return $related;
    }
}
