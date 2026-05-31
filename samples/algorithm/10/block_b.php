<?php

declare(strict_types=1);

namespace Acme\GiftCards\Allocation;

use Acme\GiftCards\Allocation\Dto\GiftCardBatch;
use Acme\GiftCards\Allocation\Dto\GiftCardDraw;

final class GiftCardPoolAllocator
{
    /**
     * @param GiftCardBatch[] $batches
     * @return GiftCardDraw[]
     */
    public function draw(array $batches, int $cardsNeeded, string $campaignId): array
    {
        usort(
            $batches,
            static fn(GiftCardBatch $a, GiftCardBatch $b): int => $a->mintedAt <=> $b->mintedAt,
        );

        $draws = [];
        $remaining = $cardsNeeded;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            if ($batch->unusedCards <= 0) {
                continue;
            }

            $take = min($remaining, $batch->unusedCards);
            $remaining -= $take;

            $draws[] = new GiftCardDraw(
                batchId: $batch->id,
                cardsTaken: $take,
                campaign: $campaignId,
            );
        }

        if ($remaining > 0) {
            throw new \RuntimeException(
                "Gift card pool exhausted: short by {$remaining} for campaign {$campaignId}",
            );
        }

        return $draws;
    }
}
