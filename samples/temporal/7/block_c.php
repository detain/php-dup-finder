<?php
declare(strict_types=1);

namespace Storefront\Recommendations;

use Experimentation\FlagClient;
use Experimentation\ExposureTracker;
use Psr\Log\LoggerInterface;

final class RecommendationController
{
    public function __construct(
        private FlagClient $flags,
        private ExposureTracker $tracker,
        private PopularityFeed $popularity,
        private PersonalizedFeed $personalized,
        private LoggerInterface $log,
    ) {}

    /**
     * @return array<int,string>
     */
    public function feed(int $userId, int $size): array
    {
        $variant = $this->flags->evaluate('recs.personalize_home', $userId, ['default' => 'control']);
        try {
            if ($variant === 'treatment') {
                $items = $this->personalized->topFor($userId, $size);
                $this->log->info('recs.treatment.ok', ['user' => $userId, 'count' => count($items)]);
                return $items;
            }
            $items = $this->popularity->top($size);
            $this->log->info('recs.control.ok', ['user' => $userId, 'count' => count($items)]);
            return $items;
        } finally {
            $this->tracker->record('recs.personalize_home', [
                'user_id' => $userId,
                'variant' => $variant,
                'at'      => date(DATE_ATOM),
            ]);
        }
    }
}
