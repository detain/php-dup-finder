<?php

declare(strict_types=1);

namespace App\Hydrator;

use App\Entity\Campaign;
use App\Entity\CampaignTarget;

final class CampaignHydrator
{
    public function hydrateFromArray(Campaign $campaign, array $data): Campaign
    {
        if (isset($data['name'])) {
            $campaign->setName($data['name']);
        }

        if (isset($data['subject'])) {
            $campaign->setSubject($data['subject']);
        }

        if (isset($data['content'])) {
            $campaign->setContent($data['content']);
        }

        if (isset($data['start_date'])) {
            $campaign->setStartDate(new \DateTime($data['start_date']));
        }

        if (isset($data['end_date'])) {
            $campaign->setEndDate(new \DateTime($data['end_date']));
        }

        if (isset($data['budget'])) {
            $campaign->setBudget((float) $data['budget']);
        }

        return $campaign;
    }

    public function hydrateTargetsFromArray(Campaign $campaign, array $targetsData): Campaign
    {
        $targets = [];

        foreach ($targetsData as $targetData) {
            $target = new CampaignTarget(
                $targetData['type'],
                $targetData['value']
            );

            if (isset($targetData['min_age'])) {
                $target->setMinAge((int) $targetData['min_age']);
            }

            if (isset($targetData['max_age'])) {
                $target->setMaxAge((int) $targetData['max_age']);
            }

            if (isset($targetData['location'])) {
                $target->setLocation($targetData['location']);
            }

            $targets[] = $target;
        }

        $campaign->setTargets($targets);

        return $campaign;
    }

    public function extractToArray(Campaign $campaign): array
    {
        return [
            'id' => $campaign->getId(),
            'name' => $campaign->getName(),
            'subject' => $campaign->getSubject(),
            'status' => $campaign->getStatus(),
            'start_date' => $campaign->getStartDate()?->format('c'),
            'end_date' => $campaign->getEndDate()?->format('c'),
            'budget' => $campaign->getBudget(),
            'spent' => $campaign->getSpent(),
            'impressions' => $campaign->getImpressions(),
            'clicks' => $campaign->getClicks(),
            'conversions' => $campaign->getConversions(),
            'created_at' => $campaign->getCreatedAt()?->format('c'),
        ];
    }

    public function extractTargetsToArray(Campaign $campaign): array
    {
        $targets = [];

        foreach ($campaign->getTargets() as $target) {
            $targets[] = [
                'type' => $target->getType(),
                'value' => $target->getValue(),
                'min_age' => $target->getMinAge(),
                'max_age' => $target->getMaxAge(),
                'location' => $target->getLocation(),
            ];
        }

        return $targets;
    }
}
