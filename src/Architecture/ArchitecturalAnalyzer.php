<?php
declare(strict_types=1);

namespace Phpdup\Architecture;

use Phpdup\Clustering\Cluster;

/**
 * Per-cluster architectural analyzer.
 *
 * Implementations are registered in {@see ArchitecturalAnalyzerRegistry}
 * and run after Ranker scoring so they can inspect impact / safety
 * / pattern tags. Each analyze() call returns 0 or more {@see Finding}s
 * that reporters render under an "Architectural notes" section.
 */
interface ArchitecturalAnalyzer
{
    /** @return list<Finding> */
    public function analyze(Cluster $cluster): array;
}
