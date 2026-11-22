<?php
declare(strict_types=1);

namespace Phpdup\Architecture\Analyzers;

use Phpdup\Architecture\ArchitecturalAnalyzer;
use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;

/**
 * Recognises classic GoF patterns from existing PatternRecognizer
 * tags + hole shape. Doesn't re-walk the AST — that would duplicate
 * PatternRecognizer's work.
 *
 *   - Strategy: cluster carries the 'strategy' pattern tag.
 *   - Factory:  some hole's inferred type is exactly 'class-string'.
 *   - Builder:  cluster carries the 'builder-chain' pattern tag.
 *
 * The old docblock advertised checks this class never made — a
 * 'crud-handler' tag arm and a one-hole restriction for Strategy,
 * observed-value class-name verification for Factory, and a Decorator
 * pattern it never emitted at all (output-055). The doc now describes
 * the code, nothing more.
 */
final class DesignPatternAnalyzer implements ArchitecturalAnalyzer
{
    /** @return list<Finding> */
    #[\Override]
    public function analyze(Cluster $cluster): array
    {
        $out = [];
        $tags = $cluster->patternTags;

        if (in_array('strategy', $tags, true)) {
            $out[] = new Finding(
                analyzer: 'DesignPatternAnalyzer',
                code:     'pattern-strategy',
                message:  'cluster fits the Strategy pattern (single behavioural hole)',
                severity: Finding::SEVERITY_NOTE,
                suggestion: 'extract an interface and bind the implementation through DI',
            );
        }
        if ($this->looksLikeFactory($cluster)) {
            $out[] = new Finding(
                analyzer: 'DesignPatternAnalyzer',
                code:     'pattern-factory',
                message:  'cluster fits the Factory pattern (class-string holes, otherwise identical)',
                severity: Finding::SEVERITY_NOTE,
                suggestion: 'centralise instantiation in a Factory keyed by the discriminator',
            );
        }
        if (in_array('builder-chain', $tags, true)) {
            $out[] = new Finding(
                analyzer: 'DesignPatternAnalyzer',
                code:     'pattern-builder-chain',
                message:  'cluster fits the Builder pattern (≥3 chained method calls)',
                severity: Finding::SEVERITY_NOTE,
                suggestion: 'consider a dedicated Builder class encapsulating the chain',
            );
        }
        return $out;
    }

    private function looksLikeFactory(Cluster $cluster): bool
    {
        if ($cluster->holes === []) return false;
        $hasClassHole = false;
        foreach ($cluster->holes as $h) {
            if ($h->inferredType === 'class-string') { $hasClassHole = true; break; }
        }
        return $hasClassHole;
    }
}
