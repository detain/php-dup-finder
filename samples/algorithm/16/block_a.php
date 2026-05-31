<?php
declare(strict_types=1);

namespace SearchEngine\Ranking;

use Psr\Log\LoggerInterface;

final class ProductSearchRanker
{
    private const TITLE_MATCH_WEIGHT = 0.30;
    private const DESCRIPTION_MATCH_WEIGHT = 0.15;
    private const CATEGORY_MATCH_WEIGHT = 0.10;
    private const PRICE_RELEVANCE_WEIGHT = 0.10;
    private const POPULARITY_WEIGHT = 0.15;
    private const RECENCY_WEIGHT = 0.10;
    private const AVAILABILITY_WEIGHT = 0.05;
    private const RATING_WEIGHT = 0.05;

    private const POPULARITY_DECAY_MONTHS = 12;
    private const RECENCY_HALFLIFE_DAYS = 30;
    private const MINIMUM_SCORE_THRESHOLD = 0.1;
    private const MAXIMUM_RESULTS = 1000;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function rankProducts(SearchQuery $query, array $products): RankedResults
    {
        $this->logger->debug('Ranking products', [
            'query' => $query->getQueryString(),
            'product_count' => count($products),
        ]);

        $scoredProducts = [];
        foreach ($products as $product) {
            $score = $this->calculateProductScore($product, $query);
            if ($score >= self::MINIMUM_SCORE_THRESHOLD) {
                $scoredProducts[] = new ScoredProduct($product, $score);
            }
        }

        usort($scoredProducts, fn($a, $b) => $b->getScore() <=> $a->getScore());

        $topResults = array_slice($scoredProducts, 0, self::MAXIMUM_RESULTS);

        $this->logger->info('Products ranked', [
            'total_matches' => count($scoredProducts),
            'returned' => count($topResults),
        ]);

        return new RankedResults($topResults, count($products));
    }

    private function calculateProductScore(Product $product, SearchQuery $query): float
    {
        $titleScore = $this->calculateTitleScore($product, $query);
        $descriptionScore = $this->calculateDescriptionScore($product, $query);
        $categoryScore = $this->calculateCategoryScore($product, $query);
        $priceScore = $this->calculatePriceRelevanceScore($product, $query);
        $popularityScore = $this->calculatePopularityScore($product);
        $recencyScore = $this->calculateRecencyScore($product);
        $availabilityScore = $this->calculateAvailabilityScore($product);
        $ratingScore = $this->calculateRatingScore($product);

        $totalScore = ($titleScore * self::TITLE_MATCH_WEIGHT)
            + ($descriptionScore * self::DESCRIPTION_MATCH_WEIGHT)
            + ($categoryScore * self::CATEGORY_MATCH_WEIGHT)
            + ($priceScore * self::PRICE_RELEVANCE_WEIGHT)
            + ($popularityScore * self::POPULARITY_WEIGHT)
            + ($recencyScore * self::RECENCY_WEIGHT)
            + ($availabilityScore * self::AVAILABILITY_WEIGHT)
            + ($ratingScore * self::RATING_WEIGHT);

        return $totalScore;
    }

    private function calculateTitleScore(Product $product, SearchQuery $query): float
    {
        $title = strtolower($product->getTitle());
        $queryTerms = $query->getSearchTerms();

        $matchCount = 0;
        foreach ($queryTerms as $term) {
            if (str_contains($title, strtolower($term))) {
                $matchCount++;
            }
        }

        if ($matchCount === 0) {
            return 0.0;
        }

        return min(1.0, $matchCount / count($queryTerms));
    }

    private function calculateDescriptionScore(Product $product, SearchQuery $query): float
    {
        $description = strtolower($product->getDescription());
        $queryTerms = $query->getSearchTerms();

        $matchCount = 0;
        foreach ($queryTerms as $term) {
            if (str_contains($description, strtolower($term))) {
                $matchCount++;
            }
        }

        if ($matchCount === 0) {
            return 0.0;
        }

        return min(1.0, $matchCount / (count($queryTerms) * 2));
    }

    private function calculateCategoryScore(Product $product, SearchQuery $query): float
    {
        $productCategories = array_map('strtolower', $product->getCategories());
        $queryCategories = array_map('strtolower', $query->getCategoryFilters());

        if (empty($queryCategories)) {
            return 0.5;
        }

        $matchCount = 0;
        foreach ($productCategories as $cat) {
            if (in_array($cat, $queryCategories)) {
                $matchCount++;
            }
        }

        return min(1.0, $matchCount / count($queryCategories));
    }

    private function calculatePriceRelevanceScore(Product $product, SearchQuery $query): float
    {
        $price = $product->getPrice();
        $targetPrice = $query->getTargetPrice();

        if ($targetPrice === null) {
            return 0.5;
        }

        $priceDiff = abs($price - $targetPrice);
        $maxDiff = $targetPrice * 2;

        $relevance = 1.0 - min(1.0, $priceDiff / $maxDiff);
        return $relevance;
    }

    private function calculatePopularityScore(Product $product): float
    {
        $salesCount = $product->getSalesCount();
        $maxSales = 10000;

        $normalizedSales = min(1.0, $salesCount / $maxSales);
        $ageMonths = $product->getAgeMonths();

        $decayFactor = max(0.3, 1.0 - ($ageMonths / self::POPULARITY_DECAY_MONTHS));
        return $normalizedSales * $decayFactor;
    }

    private function calculateRecencyScore(Product $product): float
    {
        $daysSinceCreation = $product->getDaysSinceCreation();
        $halflife = self::RECENCY_HALFLIFE_DAYS;

        $score = exp(-0.693 * $daysSinceCreation / $halflife);
        return min(1.0, $score);
    }

    private function calculateAvailabilityScore(Product $product): float
    {
        if ($product->isInStock()) {
            return 1.0;
        }
        if ($product->getStockQuantity() > 0) {
            return 0.5;
        }
        return 0.0;
    }

    private function calculateRatingScore(Product $product): float
    {
        $averageRating = $product->getAverageRating();
        $reviewCount = $product->getReviewCount();

        $ratingComponent = $averageRating / 5.0;

        $reviewWeight = min(1.0, $reviewCount / 100);
        $adjustedScore = $ratingComponent * (0.7 + 0.3 * $reviewWeight);

        return $adjustedScore;
    }
}
