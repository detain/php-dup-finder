<?php

declare(strict_types=1);

namespace App\Identification;

use Psr\Log\LoggerInterface;

final class RandomIdGenerator
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generates random IDs using cryptographically secure random bytes.
     *
     * This implementation provides uniqueness through randomness
     * and is suitable for distributed systems.
     */
    public function generate(int $length = 16): string
    {
        $bytes = random_bytes($length);

        $id = bin2hex($bytes);

        $this->logger->debug('Random ID generated', [
            'length' => $length,
            'id' => substr($id, 0, 8) . '...',
        ]);

        return $id;
    }

    /**
     * Generates IDs with a specific prefix for namespace separation.
     */
    public function generateWithPrefix(string $prefix, int $length = 16): string
    {
        $id = $this->generate($length);

        return $prefix . '_' . $id;
    }

    /**
     * Generates a range of random IDs.
     *
     * @return array<string>
     */
    public function generateRange(int $count, int $length = 16): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->generate($length);
        }

        $this->logger->debug('ID range generated', [
            'count' => $count,
            'length' => $length,
        ]);

        return $ids;
    }
}
