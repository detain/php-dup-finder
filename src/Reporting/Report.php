<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;

/**
 * In-memory analysis result. Reporters consume this; nothing else
 * should hold a reference to clusters past this point.
 */
final class Report
{
    /**
     * @param list<Cluster> $clusters
     */
    public function __construct(
        public readonly int $files,
        public readonly int $blocks,
        public readonly int $parseErrors,
        public readonly array $clusters,
        public readonly Config $config,
    ) {
    }

    public function totalDuplicatedLines(): int
    {
        $sum = 0;
        foreach ($this->clusters as $c) {
            $sum += $c->totalLines();
        }
        return $sum;
    }
}
