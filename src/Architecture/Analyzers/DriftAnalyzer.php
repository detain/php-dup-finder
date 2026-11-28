<?php
declare(strict_types=1);

namespace Phpdup\Architecture\Analyzers;

use PhpParser\Node;
use Phpdup\Architecture\ArchitecturalAnalyzer;
use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\ObservationDescriptor;

/**
 * Item 4.1: the drift analyzer — classifies WHAT diverges between the
 * majority and the minority of a cluster, using the hole rows and the
 * scalar observation descriptors AntiUnifier records (no raw nodes:
 * the socketpair law of improve_logic_info.md §4.1).
 *
 * Four predicate codes ride the `predicates` vocabulary list; a cluster
 * with no enabled vocabulary produces zero findings, so the default
 * mount (`new DriftAnalyzer()` in ReportStage, empty vocab) is inert
 * for every run until a profile/config wires it — exactly the posture
 * the guard/sanitizer/auth vocab is data-driven by (profiles/*.json
 * ship a `drift_flags` block; config plumbing is the documented
 * follow-on row).
 *
 * Predicates (doc §4.1 table):
 *   - guard-drift — optional_block holes whose template statement is a
 *     guard (If_/loop carrying Return_/throw/exit) or a configured auth
 *     call; members with a WITNESSED-absent row lack it. Absence rows
 *     without the ObservationDescriptor::ABSENT_TYPE witness are
 *     ambiguous (never-gathered pad) and are named by nobody — the
 *     analyzer may fire on the witnessed minority of a degraded
 *     alignment, but never against an unobserved row.
 *   - sanitizer-divergence — holes under an `args` path: an arm
 *     classifies each member's call names against the sanitizer/auth
 *     vocabularies (wrapped in some, raw in others ⇒ missing
 *     sanitizer/auth on the raw side); an arm compares constant/literal
 *     option values (same call, different flags ⇒ note, named option
 *     when the generalizedAst is at hand).
 *   - copy-paste-literal — literal holes whose string cites ANOTHER
 *     member's module identity (class/file/method humps) while naming
 *     none of its own ⇒ the `'Invalid VPS Passed'`-inside-Quickservers
 *     class of copy-paste evidence.
 *   - odd-one-out — a value hole where exactly one member disagrees
 *     with all the others (the `logFailure` ×10 + one extra-parameter
 *     pattern), on complete evidence only.
 *
 * Known structural limit (pinned by DriftAnalyzerTest): on a cluster of
 * EXACTLY three members whose only delta is the dropped guard, the
 * AntiUnifier seed law (fewest-holes wins, output-014) prefers the
 * guard-less template and the optional hole never forms — the signal
 * is suppressed upstream, not here. Two-member pairs and clusters of
 * four or more (where the deficient member loses the size ranking)
 * survive it; a 3-member deficient copy that REPLACES the guard with
 * any statement also survives (the hole count ties). Widening the
 * seed score (template richness on ties) is the follow-on lever.
 *
 * @see Hole for the row grammar; @see ObservationDescriptor for the
 *      wire-safe sidecar this analyzer consumes.
 */
final class DriftAnalyzer implements ArchitecturalAnalyzer
{
    public const ANALYZER = 'DriftAnalyzer';

    public const PREDICATE_GUARD       = 'guard-drift';
    public const PREDICATE_SANITIZER   = 'sanitizer-divergence';
    public const PREDICATE_LITERAL     = 'copy-paste-literal';
    public const PREDICATE_ODD_ONE_OUT = 'odd-one-out';

    /** shortType spellings of the guard-carrying statements (php-parser v5). */
    private const GUARD_TYPES = ['Stmt_If_', 'Stmt_For_', 'Stmt_Foreach_', 'Stmt_While_', 'Stmt_Do_'];

    /** Leaf types that represent an option/flag argument rather than data. */
    private const OPTION_TYPES = ['Name', 'Expr_ConstFetch', 'Scalar_String_', 'Scalar_Int_', 'Scalar_Float_'];

    /** Members named per list before the message truncates. */
    private const NAME_LIST_CAP = 4;

    /** Minimum token length for identity matching (copy-paste predicate). */
    private const TOKEN_MIN_LENGTH = 3;

    /**
     * @param array<string,mixed> $vocab profile `drift_flags` shape:
     *        predicates: list<string> of enabled predicate codes;
     *        sanitizers/auth: list<string> of lower-cased call names;
     *        options: list<string> of security-relevant option names
     *        (reserved for finding scoping — arms fire on shape today).
     */
    public function __construct(private readonly array $vocab = [])
    {
    }

    /** @return list<Finding> */
    #[\Override]
    public function analyze(Cluster $cluster): array
    {
        $enabled = $this->enabledPredicates();
        if ($enabled === []) {
            return [];
        }

        $out = [];
        if (in_array(self::PREDICATE_GUARD, $enabled, true)) {
            foreach ($this->guardDrift($cluster) as $finding) {
                $out[] = $finding;
            }
        }
        if (in_array(self::PREDICATE_SANITIZER, $enabled, true)) {
            foreach ($this->sanitizerDivergence($cluster) as $finding) {
                $out[] = $finding;
            }
        }
        if (in_array(self::PREDICATE_LITERAL, $enabled, true)) {
            foreach ($this->copyPasteLiterals($cluster) as $finding) {
                $out[] = $finding;
            }
        }
        if (in_array(self::PREDICATE_ODD_ONE_OUT, $enabled, true)) {
            foreach ($this->oddOneOut($cluster) as $finding) {
                $out[] = $finding;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function enabledPredicates(): array
    {
        $predicates = $this->vocab['predicates'] ?? null;
        if (!is_array($predicates)) {
            return [];
        }
        return array_values(array_filter($predicates, 'is_string'));
    }

    /** @return list<string> */
    private function nameList(string $vocabKey): array
    {
        $list = $this->vocab[$vocabKey] ?? null;
        if (!is_array($list)) {
            return [];
        }
        $names = [];
        foreach ($list as $name) {
            if (is_string($name) && $name !== '') {
                $names[strtolower($name)] = true;
            }
        }
        return array_keys($names);
    }

    // ---------------------------------------------------------------- guard

    /** @return list<Finding> */
    private function guardDrift(Cluster $cluster): array
    {
        $authVocab = $this->nameList('auth');
        $out       = [];
        foreach ($cluster->holes as $hole) {
            if ($hole->kind !== 'optional_block') {
                continue;
            }
            $template = $hole->templateDescriptor;
            if ($template === null) {
                continue;
            }

            $isGuard = in_array($template['type'], self::GUARD_TYPES, true)
                && $template['containsExit'] === true;
            $authHits = $authVocab === [] ? [] : array_values(array_intersect(
                array_map('strtolower', $template['callNames']),
                $authVocab
            ));
            if (!$isGuard && $authHits === []) {
                continue;
            }

            [$present, $deficient, $ambiguous] = $this->classifyPresenceRows($hole);
            if ($present === [] || $deficient === []) {
                continue;
            }

            if ($isGuard) {
                $what     = 'guard `' . $this->clip($this->rowSnippet($hole, $present)) . '`';
                $code     = 'drift-missing-guard';
                $severity = Finding::SEVERITY_ERROR;
            } else {
                $what     = 'auth call ' . implode('/', $authHits);
                $code     = 'drift-missing-auth';
                $severity = Finding::SEVERITY_ERROR;
            }
            $out[] = new Finding(
                analyzer: self::ANALYZER,
                code:     $code,
                message:  sprintf(
                    '%s: present in %d of %d members, verifiably absent in %s%s — the copies drifted apart',
                    $what,
                    count($present),
                    $present === [] ? 0 : count($present) + count($deficient) + $ambiguous,
                    $this->memberList($cluster, $deficient),
                    $ambiguous > 0 ? sprintf(' (%d further members unobserved at this row)', $ambiguous) : ''
                ),
                severity: $severity,
                suggestion: sprintf(
                    'add the %s to %s or record an accepted divergence (4.4)',
                    $isGuard ? 'guard' : 'call',
                    $this->memberList($cluster, $deficient)
                ),
            );
        }
        return $out;
    }

    /**
     * Split a presence/absence hole into witnessed-present,
     * witnessed-absent, and ambiguous (unwitnessed MISSING) rows.
     *
     * @return array{0: list<int>, 1: list<int>, 2: int}
     */
    private function classifyPresenceRows(Hole $hole): array
    {
        $present    = [];
        $deficient  = [];
        $ambiguous  = 0;
        foreach ($hole->observedValues as $i => $row) {
            if ($row !== Hole::MISSING) {
                $present[] = $i;
                continue;
            }
            if ($hole->isVerifiedAbsent($i)) {
                $deficient[] = $i;
                continue;
            }
            $ambiguous++;
        }
        return [$present, $deficient, $ambiguous];
    }

    // ------------------------------------------------------------- sanitizer

    /** @return list<Finding> */
    private function sanitizerDivergence(Cluster $cluster): array
    {
        $sanitizers = $this->nameList('sanitizers');
        $auth       = $this->nameList('auth');
        $out        = [];
        foreach ($cluster->holes as $hole) {
            $path = $cluster->holePaths[$hole->placeholder] ?? null;
            if ($path === null || !in_array('args', $path, true)) {
                continue;
            }
            if ($hole->kind === 'optional_block') {
                continue; // presence/absence is the guard predicate's channel
            }

            $finding = $sanitizers === [] && $auth === []
                ? null
                : $this->sanitizerCallDivergence($cluster, $hole, $sanitizers, $auth);
            if ($finding !== null) {
                $out[] = $finding;
                continue; // the call-shape arm owns the hole when it fires
            }
            $optionFinding = $this->optionDivergence($cluster, $hole, $path);
            if ($optionFinding !== null) {
                $out[] = $optionFinding;
            }
        }
        return $out;
    }

    /** @param list<string> $sanitizers @param list<string> $auth */
    private function sanitizerCallDivergence(Cluster $cluster, Hole $hole, array $sanitizers, array $auth): ?Finding
    {
        $raw        = [];
        $sanitized  = [];
        $authChecked = [];
        foreach ($hole->observedValues as $i => $row) {
            if ($row === Hole::MISSING) {
                continue; // unwitnessed or absent — no call-shape claim
            }
            $descriptor = $hole->descriptorAt($i);
            if ($descriptor === null) {
                continue; // legacy row without the sidecar: unjudged
            }
            $names = array_map('strtolower', $descriptor['callNames']);
            $hitSanitizers = $sanitizers === [] ? [] : array_intersect($names, $sanitizers);
            $hitAuth       = $auth === [] ? [] : array_intersect($names, $auth);
            if ($hitSanitizers !== []) {
                $sanitized[] = $i;
                continue;
            }
            if ($hitAuth !== []) {
                $authChecked[] = $i;
                continue;
            }
            $raw[] = $i;
        }

        $safe = array_merge($sanitized, $authChecked);
        if ($raw === [] || $safe === []) {
            return null;
        }

        [$bucket, $detail] = $sanitized === []
            ? ['missing auth check', $auth]
            : ['missing sanitizer', $sanitizers];
        $usedBy = [];
        foreach ($safe as $i) {
            $descriptor = $hole->descriptorAt($i);
            if ($descriptor === null) {
                continue;
            }
            foreach ($descriptor['callNames'] as $name) {
                if (in_array(strtolower($name), $bucket === 'missing sanitizer' ? $sanitizers : $auth, true)) {
                    $usedBy[$name] = true;
                }
            }
        }

        return new Finding(
            analyzer: self::ANALYZER,
            code:     $bucket === 'missing auth check' ? 'drift-missing-auth' : 'drift-missing-sanitizer',
            message: sprintf(
                '%s at %s: %s wraps the argument in %s while %s pass it raw',
                $bucket,
                $this->clip(implode('/', array_slice($this->pathTokens($hole->placeholder, $cluster), -2))),
                $this->memberList($cluster, array_unique(array_merge($sanitized, $authChecked))),
                $usedBy === [] ? 'a vocabulary call' : implode('/', array_keys($usedBy)),
                $this->memberList($cluster, $raw)
            ),
            severity: Finding::SEVERITY_ERROR,
            suggestion: sprintf('route the raw argument through %s (or record an accepted divergence)', $detail === [] ? 'the shared sanitizer' : implode('/', $detail)),
        );
    }

    /** @param list<int|string> $path */
    private function optionDivergence(Cluster $cluster, Hole $hole, array $path): ?Finding
    {
        $present = [];
        foreach ($hole->observedValues as $i => $row) {
            if ($row === Hole::MISSING) {
                return null; // a value hole with gaps is not complete option evidence
            }
            $descriptor = $hole->descriptorAt($i);
            if ($descriptor === null || !in_array($descriptor['type'], self::OPTION_TYPES, true)) {
                return null; // data argument, not an option/flag position
            }
            $present[$row] = ($present[$row] ?? 0) + 1;
        }
        if (count($present) < 2) {
            return null;
        }
        $option = $this->optionName($cluster, $path);
        $values = implode(' vs ', array_slice(array_keys($present), 0, 4));
        return new Finding(
            analyzer: self::ANALYZER,
            code:     'drift-option-divergence',
            message: sprintf(
                'option divergence%s: same call receives different arguments (%s)',
                $option === null ? '' : " on {$option}",
                $values
            ),
            severity: Finding::SEVERITY_NOTE,
            suggestion: 'decide which setting is canonical; security-relevant options should not be per-copy (doc §4.1 TLS-verify pattern)',
        );
    }

    /**
     * Resolve the option NAME for an args-path divergence by walking the
     * cluster's generalized template. Honest null when the AST is absent
     * (cache-warm runs strip it) or the call shape is unrecognizable.
     *
     * @param list<int|string> $path
     */
    private function optionName(Cluster $cluster, array $path): ?string
    {
        $root = $cluster->generalizedAst;
        if ($root === null) {
            return null;
        }
        $argsIndex = array_search('args', $path, true);
        if ($argsIndex === false || !isset($path[$argsIndex + 1]) || !is_int($path[$argsIndex + 1])) {
            return null;
        }
        $argIndex = $path[$argsIndex + 1];
        if ($argIndex < 1) {
            return null; // no sibling position to read the option name from
        }

        $current = $root;
        for ($k = 0; $k < $argsIndex; $k++) {
            $segment = $path[$k];
            if (is_int($segment)) {
                if (!is_array($current) || !isset($current[$segment])) {
                    return null;
                }
                $current = $current[$segment];
                continue;
            }
            if (!$current instanceof Node || !in_array($segment, $current->getSubNodeNames(), true)) {
                return null;
            }
            $current = $current->$segment;
        }
        if (
            !$current instanceof Node\Expr\FuncCall
            && !$current instanceof Node\Expr\MethodCall
            && !$current instanceof Node\Expr\StaticCall
            && !$current instanceof Node\Expr\New_
        ) {
            return null;
        }
        $sibling = $current->args[$argIndex - 1] ?? null;
        if (!$sibling instanceof Node\Arg) {
            return null;
        }
        $value = $sibling->value;
        if ($value instanceof Node\Name) {
            return $value->toString();
        }
        if ($value instanceof Node\Expr\ConstFetch) {
            return $value->name->toString();
        }
        if ($value instanceof Node\Scalar\String_) {
            return $value->value;
        }
        return null;
    }

    // ----------------------------------------------------------- copy-paste

    /** @return list<Finding> */
    private function copyPasteLiterals(Cluster $cluster): array
    {
        $out = [];
        if (count($cluster->members) < 2) {
            return $out;
        }
        $tokens = [];
        foreach ($cluster->members as $i => $member) {
            $tokens[$i] = $this->identityTokens($member);
        }
        foreach ($cluster->holes as $hole) {
            if ($hole->kind !== 'literal') {
                continue;
            }
            foreach ($hole->observedValues as $i => $row) {
                if ($row === Hole::MISSING) {
                    continue;
                }
                $text = $this->unquote($row);
                if ($text === null) {
                    continue; // not a plain string literal (int/float rows)
                }
                $lower = strtolower($text);
                if ($this->containsAny($lower, $tokens[$i] ?? [])) {
                    continue; // the member names its own module — not copy-paste evidence
                }
                foreach ($tokens as $j => $otherTokens) {
                    if ($j === $i) {
                        continue;
                    }
                    $hit = $this->firstContained($lower, $otherTokens);
                    if ($hit === null) {
                        continue;
                    }
                    $out[] = new Finding(
                        analyzer: self::ANALYZER,
                        code:     'drift-copy-paste-literal',
                        message: sprintf(
                            "copy-paste literal: %s carries '%s' — text naming %s, not itself",
                            $this->memberName($cluster, $i),
                            $this->clip($text),
                            $this->memberName($cluster, $j)
                        ),
                        severity: Finding::SEVERITY_WARNING,
                        suggestion: 'this string looks pasted from the cited sibling module — check user-facing text and error routing',
                    );
                    continue 2; // one citation per member row is enough
                }
            }
        }
        return $out;
    }

    /** @return list<string> lower-cased identity tokens of one member */
    private function identityTokens(Block $member): array
    {
        $sources = [];
        if ($member->class !== null && $member->class !== '') {
            $sources[] = substr($member->class, (int) strrpos($member->class, '\\') + 1);
        }
        $stem = basename($member->file);
        if (str_ends_with(strtolower($stem), '.php')) {
            $stem = substr($stem, 0, -4);
        }
        if ($stem !== '') {
            $sources[] = $stem;
        }
        if ($member->name !== null && $member->name !== '') {
            $sources[] = $member->name;
        }

        $tokens = [];
        foreach ($sources as $source) {
            foreach ($this->humps($source) as $token) {
                $token = strtolower($token);
                if (strlen($token) < self::TOKEN_MIN_LENGTH || isset($tokens[$token])) {
                    continue;
                }
                // Tokens must be word-safe for the \b matcher below;
                // embedded punctuation (namespaces' separators, snake
                // segments) is dropped rather than glued.
                if (!preg_match('/^[a-z][a-z0-9]*$/', $token)) {
                    foreach (preg_split('/[^a-z0-9]+/', $token) ?: [] as $piece) {
                        if (strlen($piece) >= self::TOKEN_MIN_LENGTH && preg_match('/^[a-z][a-z0-9]*$/', $piece)) {
                            $tokens[$piece] = true;
                        }
                    }
                    continue;
                }
                $tokens[$token] = true;
            }
        }
        return array_keys($tokens);
    }

    /** @return list<string> camel/separated word pieces of one name, plus the whole */
    private function humps(string $name): array
    {
        $out   = [$name];
        $split = preg_split('/(?<!^)(?=[A-Z])|[^A-Za-z0-9]+/', $name) ?: [];
        foreach ($split as $piece) {
            if ($piece !== '') {
                $out[] = $piece;
            }
        }
        return $out;
    }

    /** @param list<string> $tokens */
    private function containsAny(string $lowerText, array $tokens): bool
    {
        return $this->firstContained($lowerText, $tokens) !== null;
    }

    /** @param list<string> $tokens */
    private function firstContained(string $lowerText, array $tokens): ?string
    {
        foreach ($tokens as $token) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $lowerText) === 1) {
                return $token;
            }
        }
        return null;
    }

    /** Undo one var_export()/repr() single-quote layer; null when not a plain string. */
    private function unquote(string $repr): ?string
    {
        if (!preg_match("/^'((?:[^'\\\\]|\\\\.)*)'$/s", $repr, $match)) {
            return null;
        }
        return stripcslashes($match[1]);
    }

    // ------------------------------------------------------------ odd-one-out

    /** @return list<Finding> */
    private function oddOneOut(Cluster $cluster): array
    {
        $out    = [];
        $count  = count($cluster->members);
        if ($count < 3) {
            return $out;
        }
        foreach ($cluster->holes as $hole) {
            if ($hole->kind === 'optional_block') {
                continue; // presence/absence is the guard predicate's channel
            }
            if (in_array(Hole::MISSING, $hole->observedValues, true)) {
                continue; // incomplete evidence — no majority claim
            }
            $values = array_values($hole->observedValues);
            if (count($values) !== $count) {
                continue;
            }
            $counts = array_count_values($values);
            if (count($counts) !== 2) {
                continue;
            }
            arsort($counts);
            $majorityValue = (string) array_key_first($counts);
            $minorityValue = (string) array_key_last($counts);
            if ($counts[$majorityValue] !== $count - 1) {
                continue; // 2-vs-2 and worse splits are disagreement, not an odd one out
            }
            $minorityIndex = (int) array_search($minorityValue, $values, true);
            $out[] = new Finding(
                analyzer: self::ANALYZER,
                code:     'drift-odd-one-out',
                message: sprintf(
                    'odd one out: %s holds %s while the other %d members agree on %s',
                    $this->memberName($cluster, $minorityIndex),
                    $this->clip($minorityValue),
                    $count - 1,
                    $this->clip($majorityValue)
                ),
                severity: Finding::SEVERITY_WARNING,
                suggestion: 'this member diverges alone — check whether a fix landed on one side only (doc §4.2 pattern)',
            );
        }
        return $out;
    }

    // ---------------------------------------------------------------- shared

    /** @param list<int> $indexes */
    private function memberList(Cluster $cluster, array $indexes): string
    {
        $names = [];
        foreach (array_values(array_unique($indexes)) as $i) {
            $names[] = $this->memberName($cluster, $i);
        }
        if (count($names) <= self::NAME_LIST_CAP) {
            return implode(', ', $names);
        }
        return implode(', ', array_slice($names, 0, self::NAME_LIST_CAP))
            . sprintf(' (+%d more)', count($names) - self::NAME_LIST_CAP);
    }

    private function memberName(Cluster $cluster, int $index): string
    {
        $member = $cluster->members[$index] ?? null;
        if ($member === null) {
            return "member #{$index}";
        }
        return $member->qualifiedName() . ' (' . $member->location() . ')';
    }

    /** Path segments of a hole, stringified for messages (no live AST needed).
     * @return list<string> */
    private function pathTokens(string $placeholder, Cluster $cluster): array
    {
        $path = $cluster->holePaths[$placeholder] ?? [];
        return array_map('strval', $path);
    }

    /** One-line snippet of the present-side repr for the message. */
    private function rowSnippet(Hole $hole, array $presentIndexes): string
    {
        foreach ($presentIndexes as $i) {
            $row = $hole->observedValues[$i] ?? '';
            $line = strtok($row, "\n");
            if (is_string($line) && $line !== '') {
                return $line;
            }
        }
        return '<divergent>';
    }

    private function clip(string $text, int $max = 90): string
    {
        $text = str_replace(["\r", "\n"], ' ', trim($text));
        if (strlen($text) <= $max) {
            return $text;
        }
        return substr($text, 0, $max - 1) . '…';
    }
}
