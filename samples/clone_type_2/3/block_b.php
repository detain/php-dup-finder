<?php

declare(strict_types=1);

namespace App\Warehouse;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use App\Service\SearchIndexer;
use App\Service\PricingEngine;
use Psr\Log\LoggerInterface;

final class ArticleCatalogService
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly SearchIndexer $searchIndexer,
        private readonly PricingEngine $pricingEngine,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Retrieves all articles with optional filtering and sorting.
     *
     * @param array{filters?: array<string>, sort?: string, order?: string, limit?: int, offset?: int} $options
     * @return array<int, Article>
     */
    public function getAllArticles(array $options = []): array
    {
        $filters = $options['filters'] ?? [];
        $sort = $options['sort'] ?? 'created_at';
        $order = $options['order'] ?? 'desc';
        $limit = $options['limit'] ?? 50;
        $offset = $options['offset'] ?? 0;

        $queryBuilder = $this->articleRepository->createQueryBuilder('a');

        foreach ($filters as $field => $value) {
            $queryBuilder->andWhere("a.{$field} = :{$field}")
                ->setParameter($field, $value);
        }

        $queryBuilder->orderBy("a.{$sort}", $order)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $articles = $queryBuilder->getQuery()->getResult();

        $this->logger->debug('Articles retrieved', [
            'count' => count($articles),
            'filters' => $filters,
        ]);

        return $articles;
    }

    /**
     * Searches articles by keyword across title and content.
     *
     * @return array<int, Article>
     */
    public function searchArticles(string $keyword, int $limit = 20): array
    {
        $queryBuilder = $this->articleRepository->createQueryBuilder('a');

        $queryBuilder->where('a.title LIKE :keyword')
            ->orWhere('a.content LIKE :keyword')
            ->orWhere('a.reference LIKE :keyword')
            ->setParameter('keyword', '%' . $keyword . '%')
            ->setMaxResults($limit);

        $articles = $queryBuilder->getQuery()->getResult();

        $this->searchIndexer->indexArticles($articles);

        $this->logger->info('Article search completed', [
            'keyword' => $keyword,
            'results' => count($articles),
        ]);

        return $articles;
    }

    /**
     * Retrieves featured articles based on view count.
     *
     * @return array<int, Article>
     */
    public function getFeaturedArticles(int $limit = 10): array
    {
        $queryBuilder = $this->articleRepository->createQueryBuilder('a');

        $queryBuilder->select('a', 'SUM(v.viewCount) as totalViews')
            ->leftJoin('a.views', 'v')
            ->groupBy('a.id')
            ->orderBy('totalViews', 'desc')
            ->setMaxResults($limit);

        $articles = array_map(
            fn($result) => $result[0],
            $queryBuilder->getQuery()->getResult()
        );

        foreach ($articles as $article) {
            $article->setFeaturedPrice(
                $this->pricingEngine->calculateFeaturedPrice($article)
            );
        }

        $this->logger->debug('Featured articles retrieved', [
            'count' => count($articles),
        ]);

        return $articles;
    }

    /**
     * Retrieves related articles based on topic similarity.
     *
     * @return array<int, Article>
     */
    public function getRelatedArticles(int $articleId, int $limit = 5): array
    {
        $article = $this->articleRepository->findById($articleId);

        if ($article === null) {
            return [];
        }

        $queryBuilder = $this->articleRepository->createQueryBuilder('a');

        $queryBuilder->where('a.topic = :topic')
            ->andWhere('a.id != :articleId')
            ->setParameter('topic', $article->getTopic())
            ->setParameter('articleId', $articleId)
            ->setMaxResults($limit);

        $relatedArticles = $queryBuilder->getQuery()->getResult();

        $this->logger->debug('Related articles retrieved', [
            'article_id' => $articleId,
            'count' => count($relatedArticles),
        ]);

        return $relatedArticles;
    }
}
