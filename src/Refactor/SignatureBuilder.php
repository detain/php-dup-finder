<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;
use Phpdup\Util\NodePath;
use PhpParser\Node;

/**
 * Renders a PHP-style function signature for a Cluster's suggested
 * abstraction.
 *
 *   function clusterMembersFnName(
 *       <synthesized parameters>,
 *   ): mixed
 *
 * The function name is derived from the most common base name across
 * cluster members, suffixed with "By" + the holes' role names if a
 * sensible verb-by-pattern emerges (e.g. notifyByThreshold). Falls
 * back to the plain common name.
 *
 * Item 5.4: when members carry no names at all (if/foreach/array-arm
 * blocks — historically ~56% of extracted signatures on real corpora,
 * all forced to `extractedFunction`), the name is instead mined from
 * the call graph of the generalized template's hole-free statements:
 * the most frequent callee becomes the verb, the most frequent
 * property subject its object. The mining is strictly a fallback —
 * clusters with real member names keep their old suggestions
 * byte-identically — and stays silent unless the winner is unique.
 */
final class SignatureBuilder
{
    public function buildSignature(Cluster $cluster): void
    {
        $name = $this->suggestFunctionName($cluster);
        $required = [];
        $optional = [];
        // output-S49b: the reserved set is derived through the ONE shared
        // helper the apply door consults too — signature renderer and
        // call-site builder cannot disagree on which names the holes claim.
        $renderedVars = self::renderedHoleVars($cluster);
        foreach ($cluster->holes as $hole) {
            if ($hole->placeholder === AntiUnifier::CAP_SENTINEL) {
                // output-S57 r1 F1: one parameter line per hole is the
                // render rule for template gaps; the cap sentinel is not a
                // gap (zero-disagreement members routed by AntiUnifier).
                // Emitting it would fabricate `$__cap` (or, pre-synth-skip,
                // the misleading `$expr`) in an otherwise exact signature.
                continue;
            }
            $type = CodeParam::typeText($hole->inferredType);
            // Code-domain projection of the display name (CodeParam owns the
            // '$'-boundary; byte-identical for the sigil-carrying names the
            // synthesizer actually mints, so signatures stay stable).
            $var = CodeParam::varName($hole->suggestedName, $hole->placeholder);
            if ($hole->kind === 'optional_block') {
                // Optional segment → default-false bool. PHP requires defaulted
                // params at the end of the parameter list, so collect them
                // separately and append after the required ones.
                $optional[] = sprintf('    bool %s = false,', $var);
            } else {
                $required[] = sprintf('    %s%s,', $type ? $type . ' ' : '', $var);
            }
        }
        // output-S49a (php-dup-finder-output-006): the members' own
        // shared formal parameters lead the list — every member already
        // declares them at these positions, so the abstraction that
        // references them is no longer reading free variables. The
        // derivation is pure and idempotent (ParamIntersection reads the
        // member declarations, never the walk); a routed cluster and a
        // cluster without loaded member ASTs derive nothing, keeping
        // today's shape. No hole is minted and no holePaths entry is
        // touched. output-S49b wired the other side: ApplyExtractor
        // renders this same promoted set into the artifact's parameter
        // list (via the signature text) and prepends it to every call
        // site, passing each member's own locals positionally — so the
        // declared parameters and the emitted arguments agree by
        // construction.
        $promoted = [];
        foreach (ParamIntersection::promote($cluster->members, $renderedVars) as $param) {
            $promoted[] = sprintf(
                '    %s%s,',
                $param->type !== null && $param->type !== '' ? $param->type . ' ' : '',
                $param->varName(),
            );
        }
        $params = array_merge($promoted, $required, $optional);
        $body = $params ? "\n" . implode("\n", $params) . "\n" : '';
        $cluster->signature = "function {$name}({$body}): mixed";
    }

    /**
     * The variable names the cluster's rendered hole parameters claim.
     *
     * output-S49b: ONE projection shared by SignatureBuilder's own
     * promotion reserved-set and ApplyExtractor's promotion derivation at
     * the apply door. If the two consumers ever computed the set apart, a
     * promoted parameter could collide with a hole parameter in the
     * written artifact while the signature stayed clean (or vice versa) —
     * the silent double-binding class the S49a handoff named. The cap
     * sentinel is excluded exactly as in buildSignature: it never renders
     * a parameter line, so it reserves nothing.
     *
     * @return list<string> '$'-carrying names, in hole order
     */
    public static function renderedHoleVars(Cluster $cluster): array
    {
        $vars = [];
        foreach ($cluster->holes as $hole) {
            if ($hole->placeholder === AntiUnifier::CAP_SENTINEL) {
                continue;
            }
            $vars[] = CodeParam::varName($hole->suggestedName, $hole->placeholder);
        }
        return $vars;
    }

    private function suggestFunctionName(Cluster $cluster): string
    {
        $names = [];
        foreach ($cluster->members as $m) {
            if ($m->name) {
                $names[] = $m->name;
            }
        }
        if (!$names) {
            return $this->mineNameFromTemplate($cluster) ?? 'extractedFunction';
        }
        $common = $this->longestCommonPrefix($names);
        if (strlen($common) < 3) {
            $common = $names[0];
        }
        $common = rtrim($common, '_');

        // If holes look like role-by-threshold, append "By<RoleType>"
        $byParts = [];
        foreach ($cluster->holes as $hole) {
            if ($hole->kind === 'literal' && in_array($hole->inferredType, ['int', 'float'], true)) {
                $byParts[] = 'Threshold';
            } elseif ($hole->kind === 'name' || $hole->kind === 'call') {
                $byParts[] = 'Strategy';
            }
        }
        if ($byParts) {
            $byParts = array_values(array_unique($byParts));
            return $common . 'By' . implode('And', $byParts);
        }
        return $common;
    }

    /**
     * Mine a `verb`(+`Subject`) name from the template's hole-free statements.
     *
     * Hole subtrees are skipped wholesale: a callee living inside a hole is
     * member-specific data and must not leak into the shared name. The verb
     * is the strict most-frequent call name, the subject the strict
     * most-frequent property/new name; either tie or an empty verb pool
     * yields null (stay honest with the old fallback).
     */
    private function mineNameFromTemplate(Cluster $cluster): ?string
    {
        $template = $cluster->generalizedAst;
        if (!$template instanceof Node) {
            return null;
        }

        $holeKeys = [];
        foreach ($cluster->holePaths as $path) {
            $holeKeys[NodePath::keyOfList($path)] = true;
        }

        $verbs  = [];
        $nouns  = [];
        $this->mineTokens($template, NodePath::root(), $holeKeys, $verbs, $nouns);

        $verb  = $this->uniqueTop($verbs);
        $noun  = $this->uniqueTop($nouns);
        if ($verb === null) {
            return null;
        }

        $name = $noun === null ? $verb : $verb . ucfirst($noun);
        return preg_match('/^[a-z][a-zA-Z0-9_]{2,60}$/', $name) === 1 ? $name : null;
    }

    /**
     * @param NodePath                $path
     * @param array<string, true>       $holeKeys
     * @param array<string, int>        $verbs
     * @param array<string, int>        $nouns
     */
    private function mineTokens(Node $node, NodePath $path, array $holeKeys, array &$verbs, array &$nouns): void
    {
        if (isset($holeKeys[$path->key()])) {
            return;
        }

        if (
            ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall
                || $node instanceof Node\Expr\StaticCall)
            && $node->name instanceof Node\Identifier
            && !isset($holeKeys[$path->child('name')->key()])
        ) {
            $this->bump($verbs, $node->name->name);
        } elseif (
            $node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && !isset($holeKeys[$path->child('name')->key()])
        ) {
            $this->bump($verbs, $node->name->getLast());
        } elseif (
            $node instanceof Node\Expr\New_
            && $node->class instanceof Node\Name
            && !isset($holeKeys[$path->child('class')->key()])
        ) {
            $this->bump($nouns, $node->class->getLast());
        } elseif (
            $node instanceof Node\Expr\PropertyFetch
            && $node->name instanceof Node\Identifier
            && !isset($holeKeys[$path->child('name')->key()])
        ) {
            $this->bump($nouns, $node->name->name);
        }

        foreach ($node->getSubNodeNames() as $sub) {
            $child = $node->$sub;
            if ($child instanceof Node) {
                $this->mineTokens($child, $path->child($sub), $holeKeys, $verbs, $nouns);
                continue;
            }
            if (is_array($child)) {
                $subPath = $path->child($sub);
                foreach ($child as $idx => $item) {
                    if ($item instanceof Node) {
                        $this->mineTokens($item, $subPath->child($idx), $holeKeys, $verbs, $nouns);
                    }
                }
            }
        }
    }

    /** @param array<string, int> $pool */
    private function bump(array &$pool, string $token): void
    {
        // Canonicalization sentinels (DbOpCanonicalizer etc.) and magic
        // methods are plumbing, never intent.
        if (strlen($token) < 3 || str_starts_with($token, '__')) {
            return;
        }
        $pool[$token] = ($pool[$token] ?? 0) + 1;
    }

    /**
     * Strict most-frequent token, or null when the pool is empty or the top
     * count is shared by more than one token (ambiguous → don't guess).
     *
     * @param array<string, int> $pool
     */
    private function uniqueTop(array $pool): ?string
    {
        if (!$pool) {
            return null;
        }
        arsort($pool);
        $counts = array_values($pool);
        if (count($counts) > 1 && $counts[0] === $counts[1]) {
            return null;
        }
        $top = array_key_first($pool);
        return is_string($top) ? $top : null;
    }

    /** @param list<string> $names */
    private function longestCommonPrefix(array $names): string
    {
        if (!$names) return '';
        $prefix = $names[0];
        foreach ($names as $n) {
            $i = 0;
            $max = min(strlen($prefix), strlen($n));
            while ($i < $max && $prefix[$i] === $n[$i]) {
                $i++;
            }
            $prefix = substr($prefix, 0, $i);
            if ($prefix === '') return '';
        }
        return $prefix;
    }
}
