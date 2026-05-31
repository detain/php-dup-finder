<?php
declare(strict_types=1);

namespace Acme\FeatureFlags;

final class EvaluationContext
{
    private function __construct(
        private string $userId,
        private string $environment,
        private array $traits,
        private string $segment,
        private int $rolloutBucket,
    ) {
    }

    public static function forUser(string $userId): self
    {
        return new self(
            userId:        $userId,
            environment:   'production',
            traits:        [],
            segment:       'default',
            rolloutBucket: 0,
        );
    }

    public function withEnvironment(string $environment): self
    {
        $copy = clone $this;
        $copy->environment = $environment;
        return $copy;
    }

    public function withTraits(array $traits): self
    {
        $copy = clone $this;
        $copy->traits = $traits;
        return $copy;
    }

    public function withSegment(string $segment): self
    {
        $copy = clone $this;
        $copy->segment = $segment;
        return $copy;
    }

    public function withRolloutBucket(int $bucket): self
    {
        $copy = clone $this;
        $copy->rolloutBucket = $bucket;
        return $copy;
    }

    public function toKey(): string
    {
        return sprintf('%s|%s|%s|%d', $this->userId, $this->environment, $this->segment, $this->rolloutBucket);
    }
}
