<?php
declare(strict_types=1);

namespace Phpdup\Census;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use Phpdup\Util\AstSerializer;

/**
 * Subtree enumeration for the idiom census (item 3.1 letter, part 1):
 * "bottom-up enumerate every expression/statement subtree with 6-40
 * nodes inside each mined statement list".
 *
 * ONE walker law (§5c): this class performs no parsing and no file
 * reads — it consumes the statement lists the BlockExtractor's single
 * traversal already collected (the same $listEntry shape the 2.1
 * stmtseq leg mines in PreprocessWorker), so enabling the idiom flavor
 * never adds a second pass over the corpus.
 *
 * Enumeration rules, faithful to the letter:
 *  - Candidate = an Expr or Stmt node whose SUBTREE SIZE (node count —
 *    the same counting primitive the extractor's size ceilings use,
 *    AstSerializer::nodeCount) lies within [minNodes, maxNodes].
 *  - Sizes accumulate bottom-up in one iterative post-order pass per
 *    top-level statement (no recursion — a 10k-node array literal is
 *    legal PHP and the engine-004 lesson applies to every walker).
 *    A second top-down pass carries each candidate's nearest
 *    ENUMERATED ancestor: labels flow downward while sizes flow
 *    upward, so the walk is deliberately two-phase.
 *  - Nesting: the parent link feeds the census-side §5c
 *    nested-family suppression. A candidate inside a >maxNodes
 *    envelope with no in-window ancestor is a root (empty parent).
 *  - Whole-block rule: when a mined list holds exactly ONE top-level
 *    statement, that statement IS the whole block — it contributes its
 *    nested subtrees but never itself as a candidate ("skip subtrees
 *    that are exactly the whole block"). With several top-level
 *    statements each one is an ordinary candidate.
 *
 * Output is pre-aggregated PER FILE to keep the mined row's scalar
 * payload bounded (SOCKETPAIR law: the row carries strings and
 * numbers, never Node objects):
 *
 *   {
 *     sites: [ [exact, abstract, nodes, rootType, count, [lines…≤rowCap]], … ],
 *     edges: [ [childExact, parentExact, count], … ]
 *   }
 *
 * `count` folds repeated identical subtrees within the file; `lines`
 * keeps the first `rowCap` sample start-lines in deterministic walk
 * order. The doc's two-pass memory policy is enforced on the far side,
 * in {@see SubtreeCensus}, where the >=2-sites gate decides whether any
 * locations are stored at all.
 */
final class SubtreeMiner
{
    /** Node-count window floor from the 3.1 letter ("6-40 nodes"). */
    public const DEFAULT_MIN_NODES = 6;

    /** Node-count window ceiling from the 3.1 letter. */
    public const DEFAULT_MAX_NODES = 40;

    /** Sample lines carried per (file, hash) before the row is sealed. */
    public const DEFAULT_ROW_CAP = 4;

    public function __construct(
        public readonly int $minNodes = self::DEFAULT_MIN_NODES,
        public readonly int $maxNodes = self::DEFAULT_MAX_NODES,
        public readonly int $rowCap = self::DEFAULT_ROW_CAP,
    ) {
        if ($minNodes < 1 || $maxNodes < $minNodes || $rowCap < 1) {
            throw new \InvalidArgumentException(sprintf(
                'subtree window needs 1 <= min <= max and rowCap >= 1, got min=%d max=%d cap=%d',
                $minNodes,
                $maxNodes,
                $rowCap,
            ));
        }
    }

    /**
     * Mine one file's statement lists (the 2.1 §5c entry shape: keys
     * stmts/namespace/class/ownerKind/fragments — only 'stmts' is read).
     *
     * @param list<array{stmts: list<Node>, ...}> $lists
     * @return array{sites: list<array{0:string,1:string,2:int,3:string,4:int,5:list<int>}>, edges: list<array{0:string,1:string,2:int}>}
     */
    public function mineFile(array $lists): array
    {
        /** @var array<string, array{abstract:string, nodes:int, rootType:string, count:int, lines:list<int>}> $sites */
        $sites = [];
        /** @var array<string, array<string, int>> $edges childExact => parentExact => count */
        $edges = [];

        foreach ($lists as $listEntry) {
            $stmts = $listEntry['stmts'];
            if ($stmts === []) {
                continue; // the extractor never emits empty lists; belt for the foreach below
            }
            $wholeBlock = count($stmts) === 1;
            foreach ($stmts as $top) {
                foreach ($this->candidates($top, $wholeBlock) as [$node, $keys, $parentExact]) {
                    $exact  = $keys['exact'];
                    $bucket = &$sites[$exact];
                    if (!isset($bucket)) {
                        $bucket = [
                            'abstract' => $keys['abstract'],
                            'nodes'    => AstSerializer::nodeCount($node),
                            'rootType' => AstSerializer::shortType($node),
                            'count'    => 0,
                            'lines'    => [],
                        ];
                    }
                    $bucket['count']++;
                    if (count($bucket['lines']) < $this->rowCap) {
                        $line = $node->getStartLine();
                        if ($line > 0) {
                            $bucket['lines'][] = $line;
                        }
                    }
                    if ($parentExact !== '') {
                        $edges[$parentExact][$exact] = ($edges[$parentExact][$exact] ?? 0) + 1;
                    }
                }
            }
        }

        $siteRows = [];
        foreach ($sites as $exact => $b) {
            $siteRows[] = [$exact, $b['abstract'], $b['nodes'], $b['rootType'], $b['count'], $b['lines']];
        }
        // Deterministic row order regardless of hash-map insertion churn:
        // the wire payload is content, and content includes its order.
        usort($siteRows, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $edgeRows = [];
        foreach ($edges as $parentExact => $children) {
            foreach ($children as $childExact => $count) {
                $edgeRows[] = [$childExact, $parentExact, $count];
            }
        }
        usort($edgeRows, static fn(array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        return ['sites' => $siteRows, 'edges' => $edgeRows];
    }

    /**
     * Yield every enumerated subtree of one top-level statement as
     * [node, array{exact,abstract}, nearest-enumerated-ancestor-exact].
     *
     * @return list<array{0: Node, 1: array{exact:string,abstract:string}, 2: string}>
     */
    private function candidates(Node $top, bool $wholeBlock): array
    {
        $sizes = $this->sizesBottomUp($top);

        $out  = [];
        $walk = [[$top, '']]; // [node, nearest-enumerated-ancestor exact]
        while ($walk !== []) {
            [$node, $ancestor] = array_pop($walk);
            $label  = $ancestor;
            $isCand = $this->isCandidateType($node)
                && !($wholeBlock && $node === $top)
                && $sizes[spl_object_id($node)] >= $this->minNodes
                && $sizes[spl_object_id($node)] <= $this->maxNodes;
            if ($isCand) {
                $keys = SubtreeFold::hashPair($node);
                $out[] = [$node, $keys, $label];
                $label = $keys['exact'];
            }
            // Reverse-push so the pop order visits children left-first:
            // sample-line caps and emission order stay deterministic.
            foreach (array_reverse($this->childNodes($node)) as $kid) {
                $walk[] = [$kid, $label];
            }
        }
        return $out;
    }

    /**
     * Iterative post-order size table (spl_object_id => node count of
     * that node's subtree). Safe on ids: the tree stays strongly held
     * for the whole walk, so no id is recycled beneath us.
     *
     * @return array<int, int>
     */
    private function sizesBottomUp(Node $top): array
    {
        $sizes = [];
        // Frame: [node, nextChildIdx]; children cached per frame.
        $stack    = [[$top, 0]];
        /** @var list<list<Node>> $childSets */
        $childSets = [$this->childNodes($top)];
        while ($stack !== []) {
            $i    = count($stack) - 1;
            $kids = $childSets[$i];
            if ($stack[$i][1] < count($kids)) {
                $child = $kids[$stack[$i][1]];
                $stack[$i][1]++;
                $stack[]     = [$child, 0];
                $childSets[] = $this->childNodes($child);
                continue;
            }
            $total = 1;
            foreach ($kids as $kid) {
                $total += $sizes[spl_object_id($kid)];
            }
            $sizes[spl_object_id($stack[$i][0])] = $total;
            array_pop($stack);
            array_pop($childSets);
        }
        return $sizes;
    }

    /** @return list<Node> */
    private function childNodes(Node $node): array
    {
        $kids = [];
        foreach ($node->getSubNodeNames() as $sub) {
            $value = $node->$sub;
            if ($value instanceof Node) {
                $kids[] = $value;
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $kids[] = $child;
                    }
                }
            }
        }
        return $kids;
    }

    /** Whether this node type is enumerated at all (Expr or Stmt). */
    private function isCandidateType(Node $node): bool
    {
        return $node instanceof Expr || $node instanceof Stmt;
    }
}
