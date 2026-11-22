<?php
declare(strict_types=1);

namespace Phpdup\Architecture\Analyzers;

use Phpdup\Architecture\ArchitecturalAnalyzer;
use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;
use Phpdup\Refactor\PatternRecognizer;
use PhpParser\Node;

/**
 * Best-effort SOLID-violation detection.
 *
 *   - SRP (Single Responsibility): a member's body invokes BOTH a
 *     persistence-shaped verb (query, save, insert, …) AND a
 *     side-effect verb (send, log, notify, …) — likely doing two
 *     things. A verb matches only as a WHOLE identifier word — split
 *     on snake_case `_` and camelCase boundaries — so login() is no
 *     longer read as 'log' and catalog() hides no 'log' (output-055).
 *   - DIP (Dependency Inversion): a hole's inferredType is a
 *     concrete class-string (rather than an interface-shaped name)
 *     and the hole kind is structural (subtree/name).
 *
 * The SRP name scan rides the shared one-pass collector
 * ({@see PatternRecognizer::collectFacts()}, output-042) instead of a
 * private NodeFinder traversal. Since output-055 the scan reads the
 * original-case Identifier names off the collected MethodCall nodes
 * (document order preserved) rather than the lower-cased
 * MemberFacts::methodNames() roster, because camelCase word
 * boundaries only exist before lower-casing.
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

    /**
     * Verb rosters, matched as whole identifier words only:
     * output-055 killed the substring scan that read login() as 'log'
     * and matched persistence verbs incidentally inside unrelated names.
     */
    private const PERSISTENCE_VERBS = ['query', 'prepare', 'execute', 'save', 'insert', 'update', 'delete', 'find'];
    private const SIDE_EFFECT_VERBS = ['send', 'dispatch', 'fire', 'emit', 'publish', 'log', 'notify'];

    private function violatesSrp(Cluster $cluster): bool
    {
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $facts = PatternRecognizer::collectFacts($m->ast);
            $hasPersist = false;
            $hasEffect  = false;
            foreach ($facts->methodCalls as $call) {
                // Document order, original case — dynamic (non-Identifier)
                // call names carry no readable verb and are skipped,
                // exactly as MemberFacts::methodNames() skips them.
                if (!$call->name instanceof Node\Identifier) continue;
                foreach (self::identifierWords($call->name->name) as $word) {
                    if (!$hasPersist && in_array($word, self::PERSISTENCE_VERBS, true)) $hasPersist = true;
                    if (!$hasEffect  && in_array($word, self::SIDE_EFFECT_VERBS, true))  $hasEffect  = true;
                }
                if ($hasPersist && $hasEffect) return true;
            }
        }
        return false;
    }

    /**
     * The lower-cased word segments of an identifier: snake_case splits
     * on '_', camelCase on a lowercase/digit→uppercase boundary
     * (findById → find + id; login → login; writeLog → write + log).
     *
     * @return list<string>
     */
    private static function identifierWords(string $name): array
    {
        $parts = preg_split('/(?<=[a-z0-9])(?=[A-Z])|_+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map('strtolower', $parts);
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
