<?php

declare(strict_types=1);

namespace Acme\Ads\Allocation;

use Acme\Ads\Allocation\Dto\BudgetBucket;
use Acme\Ads\Allocation\Dto\ImpressionDraw;

final class ImpressionBudgetAllocator
{
    /**
     * @param BudgetBucket[] $buckets
     * @return ImpressionDraw[]
     */
    public function spendImpressions(array $buckets, int $needed, string $campaignId): array
    {
        usort(
            $buckets,
            static fn(BudgetBucket $a, BudgetBucket $b): int => $a->openedAt <=> $b->openedAt,
        );

        $draws = [];
        $remaining = $needed;

        foreach ($buckets as $bucket) {
            if ($remaining <= 0) {
                break;
            }
            if ($bucket->remainingImpressions <= 0) {
                continue;
            }

            $take = min($remaining, $bucket->remainingImpressions);
            $remaining -= $take;

            $draws[] = new ImpressionDraw(
                bucketId: $bucket->id,
                impressions: $take,
                campaign: $campaignId,
            );
        }

        if ($remaining > 0) {
            throw new \RuntimeException(
                "Budget exhausted: short by {$remaining} impressions for campaign {$campaignId}",
            );
        }

        return $draws;
    }
}
