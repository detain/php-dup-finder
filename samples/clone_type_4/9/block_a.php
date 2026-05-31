<?php

declare(strict_types=1);

namespace App\Identification;

use Psr\Log\LoggerInterface;

final class SequentialIdGenerator
{
    private int $lastId;

    public function __construct(
        private readonly LoggerInterface $logger,
        int $initialId = 0,
    ) {
        $this->lastId = $initialId;
    }

    /**
     * Generates sequential IDs starting from initial value.
     *
     * This implementation guarantees uniqueness through sequential
     * incrementing but is not suitable for distributed systems.
     */
    public function generate(): int
    {
        $this->lastId++;

        $this->logger->debug('Sequential ID generated', [
            'id' => $this->lastId,
        ]);

        return $this->lastId;
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
     * Generates a range of sequential IDs.
     *
     * @return array<int>
     */
    public function generateRange(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->generate();
        }

        $this->logger->debug('ID range generated', [
            'count' => $count,
            'start' => $ids[0],
            'end' => end($ids),
        ]);

        return $ids;
    }
}
