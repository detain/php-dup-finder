<?php
declare(strict_types=1);

namespace Phpdup\Extraction;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\LineRange;

/**
 * Resolves a Block's original AST on demand.
 *
 * Memory optimisation for very large corpora: after fingerprinting we
 * can drop {@see Block::$ast} and the AntiUnifier reloads the original
 * subtree only for the small subset of blocks that end up in clusters.
 *
 * Resolution first walks the file's parse-cached statement list for a
 * node whose kind, line-range, and (when applicable) declared name
 * match the block — the strict triple, exact whenever the range was
 * produced by this process's own scan.
 *
 * A range replayed from the cluster cache is only an ADVISORY hint:
 * content-preserving edits (comment or blank-line shifts) leave the
 * structuralHash — and therefore the corpus cache token — untouched, so
 * the cache legitimately hits while every cached line number below the
 * edit has drifted. The loader therefore keeps a per-GROUP claim ledger
 * — group = file + kind + declared name + namespace + enclosing class —
 * and every resolution (strict or re-anchored) must:
 *   • claim a physically distinct node (a span another stale member of
 *     the same group already owns is never served twice — this closes
 *     the "sibling drifted exactly onto my stale hint" door), and
 *   • preserve order: stale hints are file order (a content-preserving
 *     edit never swaps two blocks), so a member may only anchor to a
 *     candidate that sits between the nodes claimed by its hint-
 *     neighbours — the k-th stale member maps onto the k-th live
 *     candidate.
 * Within those constraints insertion drift re-anchors cleanly (span
 * preserved, nearest to the hint, below-or-at the hint wins ties).
 * Deletion drift, an exhausted candidate pool, or a truly ambiguous
 * survivor set fail LOUD by design — a silent mis-anchor poisons every
 * downstream consumer (reports, --apply), while a loud relocation error
 * merely costs one honest cache miss the operator can force. Guessing a
 * signature from the wrong sibling is never acceptable.
 */
final class BlockAstLoader
{
    /** @var array<string, list<Node\Stmt>> file → cached stmts (per-process) */
    private array $cache = [];

    /**
     * Claims per group key: hint range (as served, pre-refresh), the
     * assigned live range, and the owning Block's object id.
     *
     * @var array<string, list<array{hint: array{int,int}, assigned: array{int,int}, owner: int}>>
     */
    private array $claims = [];

    public function __construct(
        private readonly AstCache $astCache,
        private readonly AstParser $parser,
    ) {
    }

    public function resolve(Block $block): Node|null
    {
        if (!$block->isAstUnloaded()) {
            return $block->ast;
        }
        $stmts = $this->cache[$block->file] ?? null;
        if ($stmts === null) {
            $stmts = $this->astCache->get($block->file);
            if ($stmts === null) {
                $stmts = $this->parser->parseFile($block->file) ?? [];
                $this->astCache->put($block->file, $stmts);
            }
            $this->cache[$block->file] = $stmts;
        }

        $group   = self::groupKey($block);
        $foreign = $this->foreignClaims($group, $block);

        // Re-entry: this exact block was already located once in this
        // process (its range now carries the assigned truth). Serve the
        // same node without touching the ledger.
        foreach ($this->claims[$group] ?? [] as $claim) {
            if ($claim['owner'] !== spl_object_id($block)) {
                continue;
            }
            if ([$block->range->start, $block->range->end] !== $claim['assigned']) {
                continue;
            }
            $again = $this->findStrict($stmts, $block, exclude: $foreign, forceRange: $claim['assigned']);
            if ($again !== null) {
                $block->ast = $again;
                return $again;
            }
            // Self-claim no longer points at real bytes (mid-process file
            // change — not a supported flow, but never let it double-claim):
            // drop OUR entry only, foreign claims stay booked, fall through.
            $self    = spl_object_id($block);
            $this->claims[$group] = array_values(array_filter(
                $this->claims[$group] ?? [],
                static fn (array $c): bool => $c['owner'] !== $self
            ));
            break;
        }

        $hintObj = $block->range;
        $hint    = [$hintObj->start, $hintObj->end];
        $found   = $this->findStrict($stmts, $block, exclude: $foreign);
        if ($found === null) {
            $found = $this->findReanchored($stmts, $block, $hint, $foreign);
        }
        if ($found === null) {
            throw new \RuntimeException(sprintf(
                'BlockAstLoader: could not relocate block %s in %s (kind %s, hinted range %s)',
                $block->qualifiedName(), $block->file, $block->kind, (string) $hintObj
            ));
        }
        // The located node is now the truth for this block: a warm-cache
        // replay may have handed us a stale hint, and downstream
        // consumers (refactor signatures, JSON member lines) must not
        // keep reporting the drifted position.
        $block->range = new LineRange($found->getStartLine(), $found->getEndLine());
        $block->ast   = $found;
        $this->claims[$group][] = [
            'hint'     => $hint,
            'assigned' => [$found->getStartLine(), $found->getEndLine()],
            'owner'    => spl_object_id($block),
        ];
        return $found;
    }

    private static function groupKey(Block $block): string
    {
        return implode("\x1f", [
            $block->file,
            $block->kind,
            $block->name ?? '*',
            $block->namespace ?? '*',
            $block->class ?? '*',
        ]);
    }

    /**
     * Ranges already owned by OTHER blocks of the same group, plus the
     * hint each claim arrived with (order bookkeeping).
     *
     * @return list<array{hint: array{int,int}, assigned: array{int,int}}>
     */
    private function foreignClaims(string $group, Block $block): array
    {
        $out = [];
        foreach ($this->claims[$group] ?? [] as $claim) {
            if ($claim['owner'] === spl_object_id($block)) {
                continue;
            }
            $out[] = ['hint' => $claim['hint'], 'assigned' => $claim['assigned']];
        }
        return $out;
    }

    /**
     * Exact kind + line-range + name match, minus any span a foreign
     * member of the same group already claimed (a claimed node is
     * somebody else's anchor — serving it twice is the silent
     * double-anchor Vector-2 door).
     *
     * @param list<Node\Stmt>                  $stmts
     * @param list<array{hint: array{int,int}, assigned: array{int,int}}> $exclude
     * @param array{int,int}|null              $forceRange
     */
    private function findStrict(array $stmts, Block $block, array $exclude, ?array $forceRange = null): ?Node
    {
        $found = null;
        $kindMatch = static fn (Node $node, string $kind): bool => self::matchesKind($node, $kind);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($block, $found, $kindMatch, $exclude, $forceRange) extends NodeVisitorAbstract {
            /**
             * @param list<array{hint: array{int,int}, assigned: array{int,int}}> $exclude
             * @param array{int,int}|null                                         $forceRange
             */
            public function __construct(
                private readonly Block $block,
                private ?Node &$found,
                private readonly \Closure $kindMatch,
                private readonly array $exclude,
                private readonly ?array $forceRange,
            ) {
            }
            #[\Override]
            public function enterNode(Node $node)
            {
                if ($this->found !== null) {
                    return null;
                }
                $wantStart = $this->forceRange[0] ?? $this->block->range->start;
                $wantEnd   = $this->forceRange[1] ?? $this->block->range->end;
                if ($node->getStartLine() !== $wantStart) return null;
                if ($node->getEndLine()   !== $wantEnd)   return null;
                foreach ($this->exclude as $taken) {
                    if ($taken['assigned'] === [$wantStart, $wantEnd]) {
                        return null; // foreign-claimed span is never free
                    }
                }
                if (!($this->kindMatch)($node, $this->block->kind))  return null;
                if ($this->block->name !== null) {
                    $name = match (true) {
                        $node instanceof Node\Stmt\Function_   => $node->name->toString(),
                        $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
                        default                              => null,
                    };
                    if ($name !== $this->block->name) return null;
                }
                $this->found = $node;
                return null;
            }
        });
        $traverser->traverse($stmts);
        return $found;
    }

    /**
     * Range-insensitive re-anchor — the warm-cache cure.
     *
     * Candidates must agree on kind, declared name (when present),
     * enclosing namespace and class scope. Beyond that, only nodes not
     * already claimed by a foreign group member and ORDER-CONSISTENT
     * with every existing claim survive: stale hints are file order, so
     * a member anchors strictly below the node claimed by its
     * next-lower-hint neighbour and strictly above its next-higher one.
     * Among survivors the one whose span the edit preserved and whose
     * distance to the stale hint is smallest wins (insertions are the
     * promised drift direction, so a candidate below-or-at the hint
     * beats an equidistant mirror above it); a survivor-less pool or an
     * exact tie returns null so the caller keeps its loud failure.
     *
     * @param list<Node\Stmt>  $stmts
     * @param array{int,int}   $hint  [start,end] as originally served
     * @param list<array{hint: array{int,int}, assigned: array{int,int}}> $foreign
     */
    private function findReanchored(array $stmts, Block $block, array $hint, array $foreign): ?Node
    {
        $kindMatch = static fn (Node $node, string $kind): bool => self::matchesKind($node, $kind);
        $traverser = new NodeTraverser();
        $visitor = new class($block, $kindMatch) extends NodeVisitorAbstract {
            /** @var list<array{node: Node, score: array{int,int,int}}> */
            public array $candidates = [];

            private ?string $namespace = null;

            /** @var list<string> */
            private array $classStack = [];

            public function __construct(
                private readonly Block $block,
                private readonly \Closure $kindMatch,
            ) {
            }

            #[\Override]
            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString();
                    return null;
                }
                if ($node instanceof Node\Stmt\ClassLike) {
                    // MUST mirror BlockVisitor's anonymous marker verbatim
                    // ('<anonymous>', BlockExtractor.php:101): the scope
                    // filter compares against Block::$class, and a
                    // divergent sentinel silently excludes every
                    // anonymous-class member from re-anchoring (the
                    // self-scan warm replay caught this).
                    $this->classStack[] = $node->name?->toString() ?? '<anonymous>';
                    return null;
                }
                $this->consider($node);
                return null;
            }

            #[\Override]
            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = null;
                } elseif ($node instanceof Node\Stmt\ClassLike) {
                    array_pop($this->classStack);
                }
                return null;
            }

            private function consider(Node $node): void
            {
                if (!($this->kindMatch)($node, $this->block->kind)) {
                    return;
                }
                $currentClass = $this->classStack === [] ? null : end($this->classStack);
                if ($currentClass !== $this->block->class) {
                    return;
                }
                if ($this->namespace !== $this->block->namespace) {
                    return;
                }
                if ($this->block->name !== null) {
                    $name = match (true) {
                        $node instanceof Node\Stmt\Function_   => $node->name->toString(),
                        $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
                        default                              => null,
                    };
                    if ($name !== $this->block->name) {
                        return;
                    }
                }
                $hintSpan = $this->block->range->end - $this->block->range->start;
                $nodeSpan = $node->getEndLine() - $node->getStartLine();
                $dStart   = $node->getStartLine() - $this->block->range->start;
                $dEnd     = $node->getEndLine() - $this->block->range->end;
                $this->candidates[] = [
                    'node'  => $node,
                    'score' => [
                        $nodeSpan === $hintSpan ? 0 : 1,
                        abs($dStart) + abs($dEnd),
                        $dStart >= 0 ? 0 : 1,
                    ],
                ];
            }
        };
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        $pool = [];
        foreach ($visitor->candidates as $candidate) {
            $start = $candidate['node']->getStartLine();
            $span  = [$start, $candidate['node']->getEndLine()];
            $ok    = true;
            foreach ($foreign as $claim) {
                if ($claim['assigned'] === $span) {
                    $ok = false; // physically taken
                    break;
                }
                if ($hint[0] > $claim['hint'][0] && $start <= $claim['assigned'][0]) {
                    $ok = false; // must sit strictly below the lower neighbour
                    break;
                }
                if ($hint[0] < $claim['hint'][0] && $start >= $claim['assigned'][0]) {
                    $ok = false; // must sit strictly above the upper neighbour
                    break;
                }
            }
            if ($ok) {
                $pool[] = $candidate;
            }
        }

        if ($pool === []) {
            return null;
        }
        if (count($pool) === 1) {
            return $pool[0]['node'];
        }
        usort($pool, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
        if ($pool[0]['score'] === $pool[1]['score']) {
            return null; // genuinely ambiguous — fail loud upstream
        }
        return $pool[0]['node'];
    }

    private static function matchesKind(Node $node, string $kind): bool
    {
        return match ($kind) {
            'function'  => $node instanceof Node\Stmt\Function_,
            'method'    => $node instanceof Node\Stmt\ClassMethod,
            'closure'   => $node instanceof Node\Expr\Closure,
            'arrow'     => $node instanceof Node\Expr\ArrowFunction,
            'if'        => $node instanceof Node\Stmt\If_,
            'for'       => $node instanceof Node\Stmt\For_,
            'foreach'   => $node instanceof Node\Stmt\Foreach_,
            'while'     => $node instanceof Node\Stmt\While_,
            'do'        => $node instanceof Node\Stmt\Do_,
            'try'       => $node instanceof Node\Stmt\TryCatch,
            'switch'    => $node instanceof Node\Stmt\Switch_,
            'match'     => $node instanceof Node\Expr\Match_,
            default     => false,
        };
    }
}
