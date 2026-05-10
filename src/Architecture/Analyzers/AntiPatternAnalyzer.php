<?php
declare(strict_types=1);

namespace Phpdup\Architecture\Analyzers;

use Phpdup\Architecture\ArchitecturalAnalyzer;
use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;

/**
 * Detects three classic anti-patterns at the cluster level:
 *
 *   - God Class           one class hosts > GOD_THRESHOLD distinct
 *                         pattern-tag clusters → likely doing too
 *                         many things.
 *   - Primitive Obsession all holes have inferred type ∈
 *                         {int,float,string,bool} → no value objects.
 *   - Long Parameter List > LONG_PARAMS holes → suggests breaking up
 *                         the abstraction.
 */
final class AntiPatternAnalyzer implements ArchitecturalAnalyzer
{
    public const LONG_PARAMS    = 5;
    public const PRIMITIVE_TYPES = ['int', 'float', 'string', 'bool', 'mixed'];

    /** @return list<Finding> */
    public function analyze(Cluster $cluster): array
    {
        $out = [];
        if (count($cluster->holes) > self::LONG_PARAMS) {
            $out[] = new Finding(
                analyzer: 'AntiPatternAnalyzer',
                code:     'long-parameter-list',
                message:  sprintf('cluster has %d holes (> %d) — extracted method would have a long parameter list',
                    count($cluster->holes), self::LONG_PARAMS),
                severity: Finding::SEVERITY_WARNING,
                suggestion: 'consider grouping related holes into a value object before extracting',
            );
        }
        if ($this->isPrimitiveObsession($cluster)) {
            $out[] = new Finding(
                analyzer: 'AntiPatternAnalyzer',
                code:     'primitive-obsession',
                message:  'all holes are primitive scalars — abstraction may be missing value-object encapsulation',
                severity: Finding::SEVERITY_NOTE,
                suggestion: 'wrap related primitives in a small value-object (e.g. Money, EmailAddress)',
            );
        }
        return $out;
    }

    private function isPrimitiveObsession(Cluster $cluster): bool
    {
        if (count($cluster->holes) < 3) return false;
        foreach ($cluster->holes as $h) {
            $first = explode('|', $h->inferredType, 2)[0];
            if (!in_array($first, self::PRIMITIVE_TYPES, true)) return false;
        }
        return true;
    }
}
