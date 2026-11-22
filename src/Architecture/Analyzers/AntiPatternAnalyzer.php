<?php
declare(strict_types=1);

namespace Phpdup\Architecture\Analyzers;

use Phpdup\Architecture\ArchitecturalAnalyzer;
use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;

/**
 * Detects the two cluster-level anti-patterns whose evidence a single
 * Cluster can actually carry:
 *
 *   - Long Parameter List   > LONG_PARAMS holes → suggests breaking up
 *                             the abstraction.
 *   - Primitive Obsession   every hole's inferred type is composed
 *                             ONLY of the scalar arms in
 *                             PRIMITIVE_TYPES — the whole union is
 *                             tested, not just its first arm — so
 *                             value objects are likely missing.
 *
 * When no hole carries any inferred type at all (every type is
 * 'mixed', i.e. unresolvable), that is MISSING SIGNAL, not scalar
 * overuse — the opposite message — so the analyzer reports a separate
 * "all holes untyped" note instead (output-034). 'mixed' is therefore
 * deliberately absent from PRIMITIVE_TYPES.
 *
 * The old docblock advertised a God Class detector keyed on a
 * GOD_THRESHOLD constant that never existed anywhere in src/
 * (output-034); an analyze(Cluster) implementation has no cross-cluster
 * view to count clusters per class with, so the claim is gone.
 */
final class AntiPatternAnalyzer implements ArchitecturalAnalyzer
{
    public const LONG_PARAMS     = 5;
    /** True scalar arms only — see the class docblock for why 'mixed'
     *  (and 'null') are not among them (output-034). */
    public const PRIMITIVE_TYPES = ['int', 'float', 'string', 'bool'];

    /** @return list<Finding> */
    #[\Override]
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
        // Primitive obsession and the untyped posture are mutually
        // exclusive: a hole cannot be all-scalar and untyped at once.
        if ($this->isPrimitiveObsession($cluster)) {
            $out[] = new Finding(
                analyzer: 'AntiPatternAnalyzer',
                code:     'primitive-obsession',
                message:  'all holes are primitive scalars — abstraction may be missing value-object encapsulation',
                severity: Finding::SEVERITY_NOTE,
                suggestion: 'wrap related primitives in a small value-object (e.g. Money, EmailAddress)',
            );
        } elseif ($this->allHolesUntyped($cluster)) {
            $out[] = new Finding(
                analyzer: 'AntiPatternAnalyzer',
                code:     'all-holes-untyped',
                message:  'all holes are untyped (mixed) — no parameter type information could be inferred',
                severity: Finding::SEVERITY_NOTE,
                suggestion: 'treat the proposed signature as untyped until the clone sites carry explicitly typed values',
            );
        }
        return $out;
    }

    private function isPrimitiveObsession(Cluster $cluster): bool
    {
        if (count($cluster->holes) < 3) return false;
        foreach ($cluster->holes as $h) {
            if (!$this->isPrimitiveUnion($h->inferredType)) return false;
        }
        return true;
    }

    /**
     * A hole counts as primitive only when EVERY arm of its (possibly
     * union) inferred type is a scalar in PRIMITIVE_TYPES. Before
     * output-034 only explode('|')[0] was tested, so a hole typed
     * int|LoggerInterface passed the check on its first arm alone.
     */
    private function isPrimitiveUnion(string $type): bool
    {
        $type = trim($type);
        if ($type === '') return false;
        foreach (explode('|', $type) as $arm) {
            if (!in_array(trim($arm), self::PRIMITIVE_TYPES, true)) return false;
        }
        return true;
    }

    /** Every hole carries no inferred type at all ('mixed'/empty). */
    private function allHolesUntyped(Cluster $cluster): bool
    {
        if (count($cluster->holes) < 3) return false;
        foreach ($cluster->holes as $h) {
            $t = trim($h->inferredType);
            if ($t !== '' && $t !== 'mixed') return false;
        }
        return true;
    }
}
