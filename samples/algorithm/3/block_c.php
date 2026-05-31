<?php

declare(strict_types=1);

namespace Acme\Search\Ranking;

use Acme\Search\Ranking\Dto\SearchCandidate;
use Acme\Search\Ranking\Dto\RankedResult;

final class SearchResultRanker
{
    /**
     * @param SearchCandidate[] $candidates
     * @return RankedResult[]
     */
    public function rank(array $candidates): array
    {
        $weights = [
            'bm25_score'    => 0.45,
            'click_through' => 0.20,
            'freshness'     => 0.15,
            'popularity'    => 0.10,
            'authority'     => 0.10,
        ];

        $ranked = [];
        foreach ($candidates as $candidate) {
            $features = [
                'bm25_score'    => $candidate->bm25 / 25.0,
                'click_through' => $candidate->ctr,
                'freshness'     => exp(-$candidate->ageDays / 30.0),
                'popularity'    => log($candidate->views + 1, 10) / 6.0,
                'authority'     => $candidate->authorityScore,
            ];

            $score = 0.0;
            foreach ($features as $feature => $value) {
                $score += $value * ($weights[$feature] ?? 0.0);
            }

            $ranked[] = new RankedResult($candidate->documentId, round($score, 4), $features);
        }

        usort(
            $ranked,
            static fn(RankedResult $a, RankedResult $b): int => $b->score <=> $a->score,
        );

        return $ranked;
    }
}
