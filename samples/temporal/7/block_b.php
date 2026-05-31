<?php
declare(strict_types=1);

namespace Storefront\Search;

use Experimentation\FlagClient;
use Experimentation\ExposureTracker;
use Psr\Log\LoggerInterface;

final class SearchController
{
    public function __construct(
        private FlagClient $flags,
        private ExposureTracker $tracker,
        private LegacySearch $legacy,
        private VectorSearch $vector,
        private LoggerInterface $log,
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(int $userId, string $query): array
    {
        $variant = $this->flags->evaluate('search.vector_ranking', $userId, ['default' => 'control']);
        try {
            if ($variant === 'treatment') {
                $results = $this->vector->query($query, 50);
                $this->log->info('search.treatment.ok', ['user' => $userId, 'count' => count($results)]);
                return $results;
            }
            $results = $this->legacy->query($query, 50);
            $this->log->info('search.control.ok', ['user' => $userId, 'count' => count($results)]);
            return $results;
        } finally {
            $this->tracker->record('search.vector_ranking', [
                'user_id' => $userId,
                'variant' => $variant,
                'at'      => date(DATE_ATOM),
            ]);
        }
    }
}
