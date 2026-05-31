<?php
declare(strict_types=1);

namespace SearchEngine\Shared;

interface ScoringStrategy
{
    public function calculateScore(mixed $entity, SearchQuery $query): float;
    public function getWeights(): array;
}

abstract class BaseRanker
{
    protected LoggerInterface $logger;

    protected const WEIGHTS = [
        'primary' => 0.30,
        'secondary' => 0.20,
        'tertiary' => 0.15,
        'quaternary' => 0.10,
        'quinary' => 0.10,
        'senary' => 0.08,
        'septenary' => 0.05,
        'octonary' => 0.02,
    ];

    protected const RECENCY_HALFLIFE_DAYS = 30;
    protected const MINIMUM_THRESHOLD = 0.1;
    protected const MAX_RESULTS = 1000;

    public function rank(SearchQuery $query, array $entities): RankedResults
    {
        $scored = [];
        foreach ($entities as $entity) {
            $score = $this->calculateScore($entity, $query);
            if ($score >= self::MINIMUM_THRESHOLD) {
                $scored[] = new ScoredEntity($entity, $score);
            }
        }

        usort($scored, fn($a, $b) => $b->getScore() <=> $a->getScore());
        $top = array_slice($scored, 0, self::MAX_RESULTS);

        return new RankedResults($top, count($entities));
    }

    protected function calculateRecencyScore(int $daysSinceCreation): float
    {
        $halflife = self::RECENCY_HALFLIFE_DAYS;
        return min(1.0, exp(-0.693 * $daysSinceCreation / $halflife));
    }

    protected function calculateMatchScore(array $haystack, array $needles): float
    {
        if (empty($needles)) {
            return 0.5;
        }

        $matchCount = 0;
        foreach ($needles as $needle) {
            foreach ($haystack as $item) {
                if (str_contains(strtolower($item), strtolower($needle))) {
                    $matchCount++;
                    break;
                }
            }
        }

        return min(1.0, $matchCount / count($needles));
    }

    abstract protected function calculateScore(mixed $entity, SearchQuery $query): float;
}

final class ProductSearchRanker extends BaseRanker
{
    public function rankProducts(SearchQuery $query, array $products): RankedResults
    {
        return $this->rank($query, $products);
    }

    protected function calculateScore(mixed $entity, SearchQuery $query): float
    {
        $titleScore = $this->calculateMatchScore([$entity->getTitle()], $query->getSearchTerms());
        $popularityScore = min(1.0, $entity->getSalesCount() / 10000);
        $recencyScore = $this->calculateRecencyScore($entity->getDaysSinceCreation());

        return (0.35 * $titleScore)
            + (0.30 * $popularityScore)
            + (0.15 * $recencyScore)
            + (0.20 * $this->calculateAvailabilityScore($entity));
    }

    private function calculateAvailabilityScore(Product $product): float
    {
        if ($product->isInStock()) {
            return 1.0;
        }
        return $product->getStockQuantity() > 0 ? 0.5 : 0.0;
    }
}

final class ArticleSearchRanker extends BaseRanker
{
    protected const RECENCY_HALFLIFE_DAYS = 14;

    public function rankArticles(SearchQuery $query, array $articles): RankedResults
    {
        return $this->rank($query, $articles);
    }

    protected function calculateScore(mixed $entity, SearchQuery $query): float
    {
        $titleScore = $this->calculateMatchScore([$entity->getTitle()], $query->getSearchTerms());
        $contentScore = $this->calculateMatchScore([$entity->getContent()], $query->getSearchTerms());
        $recencyScore = $this->calculateRecencyScore($entity->getDaysSincePublication());

        return (0.35 * $titleScore)
            + (0.25 * $contentScore)
            + (0.20 * $recencyScore)
            + (0.20 * $this->calculateEngagementScore($entity));
    }

    private function calculateEngagementScore(Article $article): float
    {
        $views = min(1.0, $article->getViewCount() / 50000);
        $shares = min(1.0, $article->getShareCount() / 1000);
        return 0.7 * $views + 0.3 * $shares;
    }
}
