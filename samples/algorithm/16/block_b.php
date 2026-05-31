<?php
declare(strict_types=1);

namespace SearchEngine\Ranking;

use Psr\Log\LoggerInterface;

final class ArticleSearchRanker
{
    private const TITLE_MATCH_WEIGHT = 0.35;
    private const CONTENT_MATCH_WEIGHT = 0.20;
    private const AUTHOR_MATCH_WEIGHT = 0.10;
    private const TAG_MATCH_WEIGHT = 0.10;
    private const RECENCY_WEIGHT = 0.10;
    private const ENGAGEMENT_WEIGHT = 0.08;
    private const COMPLETENESS_WEIGHT = 0.05;
    private const VERIFIED_WEIGHT = 0.02;

    private const POPULARITY_DECAY_MONTHS = 12;
    private const RECENCY_HALFLIFE_DAYS = 14;
    private const MINIMUM_SCORE_THRESHOLD = 0.1;
    private const MAXIMUM_RESULTS = 500;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function rankArticles(SearchQuery $query, array $articles): RankedResults
    {
        $this->logger->debug('Ranking articles', [
            'query' => $query->getQueryString(),
            'article_count' => count($articles),
        ]);

        $scoredArticles = [];
        foreach ($articles as $article) {
            $score = $this->calculateArticleScore($article, $query);
            if ($score >= self::MINIMUM_SCORE_THRESHOLD) {
                $scoredArticles[] = new ScoredArticle($article, $score);
            }
        }

        usort($scoredArticles, fn($a, $b) => $b->getScore() <=> $a->getScore());

        $topResults = array_slice($scoredArticles, 0, self::MAXIMUM_RESULTS);

        $this->logger->info('Articles ranked', [
            'total_matches' => count($scoredArticles),
            'returned' => count($topResults),
        ]);

        return new RankedResults($topResults, count($articles));
    }

    private function calculateArticleScore(Article $article, SearchQuery $query): float
    {
        $titleScore = $this->calculateTitleScore($article, $query);
        $contentScore = $this->calculateContentScore($article, $query);
        $authorScore = $this->calculateAuthorScore($article, $query);
        $tagScore = $this->calculateTagScore($article, $query);
        $recencyScore = $this->calculateRecencyScore($article);
        $engagementScore = $this->calculateEngagementScore($article);
        $completenessScore = $this->calculateCompletenessScore($article);
        $verifiedScore = $this->calculateVerifiedScore($article);

        $totalScore = ($titleScore * self::TITLE_MATCH_WEIGHT)
            + ($contentScore * self::CONTENT_MATCH_WEIGHT)
            + ($authorScore * self::AUTHOR_MATCH_WEIGHT)
            + ($tagScore * self::TAG_MATCH_WEIGHT)
            + ($recencyScore * self::RECENCY_WEIGHT)
            + ($engagementScore * self::ENGAGEMENT_WEIGHT)
            + ($completenessScore * self::COMPLETENESS_WEIGHT)
            + ($verifiedScore * self::VERIFIED_WEIGHT);

        return $totalScore;
    }

    private function calculateTitleScore(Article $article, SearchQuery $query): float
    {
        $title = strtolower($article->getTitle());
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

    private function calculateContentScore(Article $article, SearchQuery $query): float
    {
        $content = strtolower($article->getContent());
        $queryTerms = $query->getSearchTerms();

        $matchCount = 0;
        foreach ($queryTerms as $term) {
            if (str_contains($content, strtolower($term))) {
                $matchCount++;
            }
        }

        if ($matchCount === 0) {
            return 0.0;
        }

        return min(1.0, $matchCount / (count($queryTerms) * 3));
    }

    private function calculateAuthorScore(Article $article, SearchQuery $query): float
    {
        $articleAuthor = strtolower($article->getAuthor());
        $queryAuthors = array_map('strtolower', $query->getAuthorFilters());

        if (empty($queryAuthors)) {
            return 0.5;
        }

        return in_array($articleAuthor, $queryAuthors) ? 1.0 : 0.0;
    }

    private function calculateTagScore(Article $article, SearchQuery $query): float
    {
        $articleTags = array_map('strtolower', $article->getTags());
        $queryTags = array_map('strtolower', $query->getTagFilters());

        if (empty($queryTags)) {
            return 0.5;
        }

        $matchCount = 0;
        foreach ($articleTags as $tag) {
            if (in_array($tag, $queryTags)) {
                $matchCount++;
            }
        }

        return min(1.0, $matchCount / count($queryTags));
    }

    private function calculateRecencyScore(Article $article): float
    {
        $daysSincePublication = $article->getDaysSincePublication();
        $halflife = self::RECENCY_HALFLIFE_DAYS;

        $score = exp(-0.693 * $daysSincePublication / $halflife);
        return min(1.0, $score);
    }

    private function calculateEngagementScore(Article $article): float
    {
        $views = $article->getViewCount();
        $maxViews = 50000;

        $shares = $article->getShareCount();
        $maxShares = 1000;

        $comments = $article->getCommentCount();
        $maxComments = 500;

        $normalizedViews = min(1.0, $views / $maxViews);
        $normalizedShares = min(1.0, $shares / $maxShares);
        $normalizedComments = min(1.0, $comments / $maxComments);

        return (0.5 * $normalizedViews) + (0.3 * $normalizedShares) + (0.2 * $normalizedComments);
    }

    private function calculateCompletenessScore(Article $article): float
    {
        $wordCount = $article->getWordCount();
        $minWords = 300;
        $idealWords = 1500;

        if ($wordCount < $minWords) {
            return $wordCount / $minWords * 0.5;
        }

        return min(1.0, $wordCount / $idealWords);
    }

    private function calculateVerifiedScore(Article $article): float
    {
        return $article->isVerified() ? 1.0 : 0.5;
    }
}
