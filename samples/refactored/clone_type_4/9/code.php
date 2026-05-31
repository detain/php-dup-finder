<?php

declare(strict_types=1);

namespace App\Identification;

use Psr\Log\LoggerInterface;

interface IdGeneratorInterface
{
    public function generate(): string;
    public function generateWithPrefix(string $prefix): string;
    public function generateRange(int $count): array;
}

abstract class AbstractIdGenerator implements IdGeneratorInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function generateWithPrefix(string $prefix): string
    {
        return $prefix . '_' . $this->generate();
    }

    public function generateRange(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->generate();
        }

        $this->logger->debug('ID range generated', [
            'count' => $count,
        ]);

        return $ids;
    }
}

final class SequentialIdGenerator extends AbstractIdGenerator
{
    private int $lastId;

    public function __construct(LoggerInterface $logger, int $initialId = 0)
    {
        parent::__construct($logger);
        $this->lastId = $initialId;
    }

    public function generate(): string
    {
        return (string) ++$this->lastId;
    }
}

final class RandomIdGenerator extends AbstractIdGenerator
{
    public function generate(int $length = 16): string
    {
        return bin2hex(random_bytes($length));
    }

    public function generateWithPrefix(string $prefix, int $length = 16): string
    {
        return $prefix . '_' . $this->generate($length);
    }

    public function generateRange(int $count, int $length = 16): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->generate($length);
        }

        return $ids;
    }
}

final class TimeBasedIdGenerator extends AbstractIdGenerator
{
    public function generate(): string
    {
        $timestamp = (int) (microtime(true) * 1000);
        $random = random_int(1000, 9999);

        return sprintf('%013d%04d', $timestamp, $random);
    }

    public function generateRange(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->generate();
            usleep(1000);
        }

        return $ids;
    }
}
