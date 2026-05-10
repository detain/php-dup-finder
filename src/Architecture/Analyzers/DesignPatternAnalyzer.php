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
 *   - Strategy: existing tag 'strategy' or 'crud-handler' with one
 *               'name' / 'call' hole.
 *   - Factory:  cluster's signature contains 'class-string' hole
 *               and the holes' observed values are class names.
 *   - Decorator: builder-chain tag + holes that look like wrappers.
 */
final class DesignPatternAnalyzer implements ArchitecturalAnalyzer
{
    /** @return list<Finding> */
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
