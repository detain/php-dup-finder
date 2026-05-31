<?php

declare(strict_types=1);

namespace App\Identification;

use Psr\Log\LoggerInterface;

final class TimeBasedIdGenerator
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generates time-based IDs combining timestamp with random component.
     *
     * This implementation provides uniqueness through time + randomness
     * and sortable IDs for temporal ordering.
     */
    public function generate(): string
    {
        $timestamp = (int) (microtime(true) * 1000);
        $random = random_int(1000, 9999);

        $id = sprintf('%013d%04d', $timestamp, $random);

        $this->logger->debug('Time-based ID generated', [
            'timestamp' => $timestamp,
            'random' => $random,
            'id' => $id,
        ]);

        return $id;
    }

    /**
     * Generates IDs with a specific prefix for namespace separation.
     */
    public function generateWithPrefix(string $prefix): string
    {
        $id = $this->generate();

        return $prefix . '_' . $id;
    }

    /**
     * Generates a range of time-based IDs with guaranteed ordering.
     *
     * @return array<string>
     */
    public function generateRange(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->generate();
            usleep(1000);
        }

        $this->logger->debug('ID range generated', [
            'count' => $count,
        ]);

        return $ids;
    }
}
