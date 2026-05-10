<?php
declare(strict_types=1);

namespace Phpdup\Architecture\Analyzers;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Phpdup\Architecture\ArchitecturalAnalyzer;
use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;

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
 */
final class SolidAnalyzer implements ArchitecturalAnalyzer
{
    /** @return list<Finding> */
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
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $names = $finder->find([$m->ast], static function (Node $n) {
                if ($n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier) {
                    return true;
                }
                return false;
            });
            $hasPersist = false;
            $hasEffect  = false;
            foreach ($names as $call) {
                if (!$call instanceof Node\Expr\MethodCall) continue;
                if (!$call->name instanceof Node\Identifier) continue;
                $n = strtolower($call->name->name);
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
