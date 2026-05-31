<?php
declare(strict_types=1);

namespace SearchEngine\Ranking;

use Psr\Log\LoggerInterface;

final class UserSearchRanker
{
    private const NAME_MATCH_WEIGHT = 0.30;
    private const BIO_MATCH_WEIGHT = 0.15;
    private const SKILL_MATCH_WEIGHT = 0.20;
    private const LOCATION_MATCH_WEIGHT = 0.10;
    private const ACTIVITY_WEIGHT = 0.10;
    private const REPUTATION_WEIGHT = 0.08;
    private const COMPLETENESS_WEIGHT = 0.05;
    private const VERIFICATION_WEIGHT = 0.02;

    private const POPULARITY_DECAY_MONTHS = 12;
    private const RECENCY_HALFLIFE_DAYS = 30;
    private const MINIMUM_SCORE_THRESHOLD = 0.1;
    private const MAXIMUM_RESULTS = 200;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function rankUsers(SearchQuery $query, array $users): RankedResults
    {
        $this->logger->debug('Ranking users', [
            'query' => $query->getQueryString(),
            'user_count' => count($users),
        ]);

        $scoredUsers = [];
        foreach ($users as $user) {
            $score = $this->calculateUserScore($user, $query);
            if ($score >= self::MINIMUM_SCORE_THRESHOLD) {
                $scoredUsers[] = new ScoredUser($user, $score);
            }
        }

        usort($scoredUsers, fn($a, $b) => $b->getScore() <=> $a->getScore());

        $topResults = array_slice($scoredUsers, 0, self::MAXIMUM_RESULTS);

        $this->logger->info('Users ranked', [
            'total_matches' => count($scoredUsers),
            'returned' => count($topResults),
        ]);

        return new RankedResults($topResults, count($users));
    }

    private function calculateUserScore(User $user, SearchQuery $query): float
    {
        $nameScore = $this->calculateNameScore($user, $query);
        $bioScore = $this->calculateBioScore($user, $query);
        $skillScore = $this->calculateSkillScore($user, $query);
        $locationScore = $this->calculateLocationScore($user, $query);
        $activityScore = $this->calculateActivityScore($user);
        $reputationScore = $this->calculateReputationScore($user);
        $completenessScore = $this->calculateCompletenessScore($user);
        $verificationScore = $this->calculateVerificationScore($user);

        $totalScore = ($nameScore * self::NAME_MATCH_WEIGHT)
            + ($bioScore * self::BIO_MATCH_WEIGHT)
            + ($skillScore * self::SKILL_MATCH_WEIGHT)
            + ($locationScore * self::LOCATION_MATCH_WEIGHT)
            + ($activityScore * self::ACTIVITY_WEIGHT)
            + ($reputationScore * self::REPUTATION_WEIGHT)
            + ($completenessScore * self::COMPLETENESS_WEIGHT)
            + ($verificationScore * self::VERIFICATION_WEIGHT);

        return $totalScore;
    }

    private function calculateNameScore(User $user, SearchQuery $query): float
    {
        $fullName = strtolower($user->getFullName());
        $queryTerms = $query->getSearchTerms();

        $matchCount = 0;
        foreach ($queryTerms as $term) {
            if (str_contains($fullName, strtolower($term))) {
                $matchCount++;
            }
        }

        if ($matchCount === 0) {
            return 0.0;
        }

        return min(1.0, $matchCount / count($queryTerms));
    }

    private function calculateBioScore(User $user, SearchQuery $query): float
    {
        $bio = strtolower($user->getBio());
        $queryTerms = $query->getSearchTerms();

        $matchCount = 0;
        foreach ($queryTerms as $term) {
            if (str_contains($bio, strtolower($term))) {
                $matchCount++;
            }
        }

        if ($matchCount === 0) {
            return 0.0;
        }

        return min(1.0, $matchCount / (count($queryTerms) * 2));
    }

    private function calculateSkillScore(User $user, SearchQuery $query): float
    {
        $userSkills = array_map('strtolower', $user->getSkills());
        $querySkills = array_map('strtolower', $query->getSkillFilters());

        if (empty($querySkills)) {
            return 0.5;
        }

        $matchCount = 0;
        foreach ($userSkills as $skill) {
            if (in_array($skill, $querySkills)) {
                $matchCount++;
            }
        }

        return min(1.0, $matchCount / count($querySkills));
    }

    private function calculateLocationScore(User $user, SearchQuery $query): float
    {
        $userLocation = strtolower($user->getLocation());
        $queryLocations = array_map('strtolower', $query->getLocationFilters());

        if (empty($queryLocations)) {
            return 0.5;
        }

        $matchCount = 0;
        foreach ($queryLocations as $location) {
            if (str_contains($userLocation, $location)) {
                $matchCount++;
            }
        }

        return min(1.0, $matchCount / count($queryLocations));
    }

    private function calculateActivityScore(User $user): float
    {
        $daysSinceLastActive = $user->getDaysSinceLastActive();
        $halflife = self::RECENCY_HALFLIFE_DAYS;

        $activityScore = exp(-0.693 * $daysSinceLastActive / $halflife);
        return min(1.0, $activityScore);
    }

    private function calculateReputationScore(User $user): float
    {
        $reputationPoints = $user->getReputationPoints();
        $maxReputation = 10000;

        return min(1.0, $reputationPoints / $maxReputation);
    }

    private function calculateCompletenessScore(User $user): float
    {
        $fields = [
            $user->getFullName() !== '',
            $user->getBio() !== '',
            $user->getAvatarUrl() !== '',
            count($user->getSkills()) > 0,
            $user->getLocation() !== '',
        ];

        return array_sum($fields) / count($fields);
    }

    private function calculateVerificationScore(User $user): float
    {
        return $user->isVerified() ? 1.0 : 0.3;
    }
}
