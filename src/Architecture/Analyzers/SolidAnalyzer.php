<?php
declare(strict_types=1);

namespace Phpdup\Architecture\Analyzers;

use Phpdup\Architecture\ArchitecturalAnalyzer;
use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;
use Phpdup\Refactor\PatternRecognizer;

/**
 * Best-effort SOLID-violation detection.
 *
 *   - SRP (Single Responsibility): a member's body invokes BOTH a
 *     persistence-shaped call (db->query, ->save, ->insert) AND a
 *     side-effect call (Mail/Logger/Mailer/->send) — likely doing
 *     two things.
 *   - DIP (Dependency Inversion): a hole's inferredType is a
 *     concrete class-string (rather than an interface-shaped name)
 *     and the hole kind is structural (subtree/name).
 *
 * The SRP name scan rides the shared one-pass collector
 * ({@see PatternRecognizer::collectFacts()}, output-042) instead of a
 * private NodeFinder traversal: call names arrive in the same document
 * order the old collect-then-scan produced, so findings are unchanged.
 */
final class SolidAnalyzer implements ArchitecturalAnalyzer
{
    /** @return list<Finding> */
    #[\Override]
    public function analyze(Cluster $cluster): array
    {
        $out = [];
        if ($this->violatesSrp($cluster)) {
            $out[] = new Finding(
                analyzer: 'SolidAnalyzer',
                code:     'srp-mixed-concerns',
                message:  'cluster body mixes persistence and side-effect calls — likely violates Single Responsibility',
                severity: Finding::SEVERITY_NOTE,
                suggestion: 'split persistence + side-effect concerns into separate collaborators',
            );
        }
        $dip = $this->dipCandidates($cluster);
        if ($dip !== []) {
            $out[] = new Finding(
                analyzer: 'SolidAnalyzer',
                code:     'dip-concrete-strategy',
                message:  sprintf(
                    'hole(s) %s have concrete class-string types — abstracting against an interface would invert the dependency',
                    implode(', ', $dip),
                ),
                severity: Finding::SEVERITY_NOTE,
            );
        }
        return $out;
    }

    private function violatesSrp(Cluster $cluster): bool
    {
        $persistence = ['query', 'prepare', 'execute', 'save', 'insert', 'update', 'delete', 'find'];
        $sideEffects = ['send', 'dispatch', 'fire', 'emit', 'publish', 'log', 'notify'];
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $facts = PatternRecognizer::collectFacts($m->ast);
            $hasPersist = false;
            $hasEffect  = false;
            foreach ($facts->methodNames() as $n) {
                foreach ($persistence as $p) {
                    if (str_contains($n, $p)) { $hasPersist = true; break; }
                }
                foreach ($sideEffects as $s) {
                    if (str_contains($n, $s)) { $hasEffect = true; break; }
                }
                if ($hasPersist && $hasEffect) return true;
            }
        }
        return false;
    }

    /** @return list<string> hole placeholder names that look like DIP candidates */
    private function dipCandidates(Cluster $cluster): array
    {
        $out = [];
        foreach ($cluster->holes as $h) {
            if ($h->inferredType !== 'class-string') continue;
            if (!in_array($h->kind, ['subtree', 'name'], true)) continue;
            $out[] = $h->placeholder;
        }
        return $out;
    }
}
