<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Normalization\Normalizer;
use Phpdup\Normalization\DbOpRegistry;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\PrettyPrinter\Standard;
use Phpdup\Util\LineRange;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ClusterStage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Relocation pins for the lazy-AST loader (fix round, review item [M]-1).
 *
 * The warm cluster cache replays member blocks with the line ranges they
 * had when the cache was written; a comment-only edit above those members
 * keeps every structuralHash (so the cache hit is correct by design) while
 * silently moving the real lines. resolve() must re-anchor from content —
 * kind + name + scope first, then an ORDER-PRESERVING claim ledger per
 * (file, kind, name, namespace, class) group — refreshing the block's
 * range, or failing loud with kind + stale hint in the message. Silence
 * is only ever acceptable when every member landed on its own node;
 * deletion-direction drift is intentionally loud (round-2 review [M-1]).
 */
final class BlockAstLoaderTest extends TestCase
{
    private string $dir;

    private AstParser $parser;

    protected function setUp(): void
    {
        $this->dir    = sys_get_temp_dir() . '/bal-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->parser = new AstParser();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testStrictHitResolvesAnUnloadedBlockWithFreshRanges(): void
    {
        $file  = $this->write('a.php', self::oneFunction());
        $block = $this->functionBlock($file, 'measure_alpha');

        $loader = $this->loader();
        $block->unloadAst();
        $node = $loader->resolve($block);

        self::assertInstanceOf(Node\Stmt\Function_::class, $node);
        self::assertSame('measure_alpha', (string) $node->name, 'strict path must land on the same function');
    }

    public function testCommentDriftAboveABlockReanchorsAndRefreshesItsRange(): void
    {
        $src   = self::oneFunction();
        $file  = $this->write('b.php', $src);
        $block = $this->functionBlock($file, 'measure_alpha');
        $oldStart = $block->range->start;

        // Two lines inserted ABOVE the function; nothing inside moves
        // relative to itself — this is exactly the cluster-cache replay
        // hazard: the stale hint is now wrong by +2.
        $this->insertAfterOpen($file, 2);
        $block->unloadAst();

        $loader = $this->loader();
        $node   = $loader->resolve($block);

        self::assertInstanceOf(Node\Stmt\Function_::class, $node);
        self::assertSame($oldStart + 2, $block->range->start, 'relocated range must be refreshed');
        self::assertSame($node->getStartLine(), $block->range->start);
        self::assertSame($node->getEndLine(), $block->range->end);
        self::assertSame($block->ast, $node, 'resolve() must attach the located node');
    }

    public function testMirrorCandidatesTieBreakTowardTheInsertionSide(): void
    {
        // Two 3-line if blocks inside the same class scope, separated by a
        // blank line. After inserting two lines above the second one, the
        // stale hint for the SECOND if sits exactly equidistant between the
        // (now-unchanged) first if and the (shifted) second. The
        // insertion-bias lever must pick the candidate at-or-below the
        // hint — a pure insert above a block shifts it DOWN, never up.
        $file = $this->write('c.php', self::twoTwinIfs());
        [$first, $hint] = $this->ifBlocks($file);

        // Sanity: the two 3-line ifs are separated by one blank line.
        self::assertSame($first->range->end + 2, $hint->range->start);
        self::assertSame($hint->range->end - $hint->range->start, $first->range->end - $first->range->start);

        $this->insertAfterOpen($file, 2);
        $hint->unloadAst();

        $this->loader()->resolve($hint);

        // Re-derive truth from the edited file: the second if is now +2.
        $moved = $this->ifRanges($file);
        self::assertCount(2, $moved);
        self::assertSame($moved[1], [$hint->range->start, $hint->range->end],
            'the equidistant mirror above must lose to the true (downward-shifted) block');
    }

    public function testScopeFilterBeatsACloserSameKindBlockInAnotherClass(): void
    {
        $file  = $this->write('d.php', self::splitScopeIfs());
        $block = $this->ifBlockIn($file, 'Upper', 1);

        // Insert 10 lines near the top: Upper's if moves +10; the
        // decoy if in Lower was already closer to the stale hint and
        // moves too — scope must decide, not distance.
        $this->insertAfterOpen($file, 10);
        $block->unloadAst();

        $this->loader()->resolve($block);

        self::assertSame('Upper', $block->class);
        $ranges = $this->ifRanges($file, classFilter: 'Upper');
        self::assertSame($ranges[0], [$block->range->start, $block->range->end],
            'relocation must land inside the block\'s own class scope');
    }

    /**
     * Review round-2 [M-1] Vector 1: deleting comment lines above TWO
     * same-group members shifts both up; the pure nearest-with-bias
     * scorer hands the LOWER node to the UPPER member (distance term),
     * pre-fix double-anchored both and dropped the true block with
     * RC=0. Post-fix the second member's order window is exhausted and
     * the run dies LOUD; the hint-descending order resolves honestly.
     * Silent mis-pairing must never be observable.
     */
    public function testDeletionDriftOverTwinMembersNeverSilentlyMisAnchors(): void
    {
        $file = $this->write('f.php', self::twinIfsWithTopFillers());
        $probe = $this->freshIfPair($file);
        self::assertSame([23, 25], [$probe[0]->range->start, $probe[0]->range->end]);
        self::assertSame([30, 32], [$probe[1]->range->start, $probe[1]->range->end]);

        $this->deleteFileLines($file, 8, 15); // 15 comment lines vanish above both
        // Truth after deletion: [8,10] and [15,17].

        // File order (upper member first — what the cluster member list
        // actually is): the upper member pulls the lower node; the lower
        // member must then fail LOUD instead of double-anchoring.
        $loud = null;
        $pair = $this->freshIfPair($file, [[23, 25], [30, 32]]);
        try {
            $loader = $this->loader();
            $loader->resolve($pair[0]);
            $loader->resolve($pair[1]);
        } catch (\RuntimeException $e) {
            $loud = $e->getMessage();
        }
        if ($loud !== null) {
            self::assertStringContainsString('could not relocate', $loud);
            self::assertStringContainsString('kind if', $loud);
        } else {
            // The only permitted silent completion is the honest pairing.
            self::assertSame([8, 10], [$pair[0]->range->start, $pair[0]->range->end]);
            self::assertSame([15, 17], [$pair[1]->range->start, $pair[1]->range->end]);
        }

        // Hint-descending order: monotone constraints land both members
        // on their true nodes — deletions are loud OR correct.
        $loader = $this->loader();
        $pair = $this->freshIfPair($file, [[23, 25], [30, 32]]);
        $loader->resolve($pair[1]);
        $loader->resolve($pair[0]);
        self::assertSame([8, 10], [$pair[0]->range->start, $pair[0]->range->end]);
        self::assertSame([15, 17], [$pair[1]->range->start, $pair[1]->range->end]);
    }

    /**
     * Review round-2 [M-1] Vector 2: an 8-line comment deletion moves
     * sibling B from [24,26] EXACTLY onto stale member A's hint [16,18].
     * Pre-fix, findStrict served B's node to A with zero uniqueness
     * checks and A's truth [8,10] vanished silently. Post-fix either the
     * steal is refused (B first ⇒ both correct) or the robbed member
     * starves loudly (A first ⇒ B dies on an exhausted window).
     */
    public function testSiblingDriftingOntoAStaleHintIsLoudOrCorrectNeverSilent(): void
    {
        $file = $this->write('g.php', self::vector2Geometry());
        $probe = $this->freshIfPair($file);
        self::assertSame([16, 18], [$probe[0]->range->start, $probe[0]->range->end]);
        self::assertSame([24, 26], [$probe[1]->range->start, $probe[1]->range->end]);

        $this->deleteFileLines($file, 8, 8); // A -> [8,10]; B -> [16,18] exactly A's stale hint

        // B first: it re-anchors honestly to [16,18]; then A's strict hit
        // on that foreign-CLAIMED span must be refused and A must land
        // on its true [8,10].
        $loader = $this->loader();
        [$a, $b] = $this->freshIfPair($file, [[16, 18], [24, 26]]);
        $loader->resolve($b);
        $loader->resolve($a);
        self::assertSame([16, 18], [$b->range->start, $b->range->end]);
        self::assertSame([8, 10], [$a->range->start, $a->range->end],
            'a strict hit already claimed by another member must not be served twice');

        // Fresh state, A first: A momentarily takes [16,18] (its hint),
        // but B's window then exhausts — loud death, never a silent
        // both-anchored report.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not relocate/');
        $loader2 = $this->loader();
        [$a2, $b2] = $this->freshIfPair($file, [[16, 18], [24, 26]]);
        $loader2->resolve($a2);
        $loader2->resolve($b2);
    }

    public function testThirdMemberWithNoLiveCounterpartFailsLoud(): void
    {
        // Count law: two stale hints anchor (unchanged file, strict
        // path), a third stale hint from an older cache has no node
        // left to claim — loud, not a re-serve of an anchored span.
        $file   = $this->write('h.php', self::twoTwinIfs());
        [$u, $l] = $this->ifBlocks($file);
        $loader = $this->loader();
        $u->unloadAst();
        $l->unloadAst();
        $loader->resolve($u);
        $loader->resolve($l);

        [$ul, $ll] = $this->ifBlocks($file);
        $ghost = new Block($file, $ll->range, 'if', 'TwinLand', 'TwinHost', null, $ul->ast ?? new Node\Stmt\Nop());
        $ghost->unloadAst();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not relocate/');
        $loader->resolve($ghost);
    }

    public function testAnonymousClassMembersReanchorLikeNamedOnes(): void
    {
        // Regression pin for the sentinel bug the src/ self-scan warm
        // replay caught: BlockVisitor marks anonymous classes as
        // '<anonymous>' (BlockExtractor.php:101) and stores exactly that
        // in Block::$class — the loader's scope filter must use the same
        // literal. A divergent sentinel silently drops every
        // anonymous-class candidate, turning an honest insertion drift
        // into a loud 'could not relocate' on real code (the loader's own
        // visitors' guard clauses in this very file).
        $file  = $this->write('g.php', self::anonymousTwinIfs());
        $pairs = $this->anonymousIfBlocks($file);
        self::assertCount(2, $pairs);

        $before = array_map(static fn (Block $b): array => [$b->range->start, $b->range->end], $pairs);

        $this->insertAfterOpen($file, 2);
        foreach ($pairs as $p) {
            $p->unloadAst();
        }

        $loader = $this->loader();
        $loader->resolve($pairs[0]);
        $loader->resolve($pairs[1]);

        $live = $this->ifRanges($file);
        self::assertSame($live, [
            [$pairs[0]->range->start, $pairs[0]->range->end],
            [$pairs[1]->range->start, $pairs[1]->range->end],
        ], 'pure +2 insertion drift inside anonymous scope must re-anchor honestly, in file order');
        self::assertSame([$before[0][0] + 2, $before[0][1] + 2], $live[0]);
    }

    public function testDeletedBlockStillFailsLoudWithKindAndHintedRange(): void
    {
        $file  = $this->write('e.php', self::oneFunction());
        $block = $this->functionBlock($file, 'measure_alpha');
        $hint  = (string) $block->range;

        // Content removal: no candidate at any distance — loud throw.
        file_put_contents($file, "<?php\n// the function is gone\n");
        $block->unloadAst();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/could not relocate block .* \(kind function, hinted range ' . preg_quote($hint, '/') . '\)/'
        );
        $this->loader()->resolve($block);
    }

    // ------------------------------------------- round-4 content binding

    /**
     * Review round-3 [M-1]: with a threaded Config, re-anchoring is
     * CONTENT-BOUND — a candidate may only serve a member whose stored
     * structuralHash it reproduces through the same normalization
     * pipeline. Same-kind decoys (sub-threshold echo-ifs that were never
     * cluster members) can no longer win on positional score at all.
     */
    public function testDistinctContentTwinsAnchorOrderFree(): void
    {
        // The crown property: members with MUTUALLY DISTINCT content each
        // have exactly one live twin, so every stale hint re-anchors to
        // ITS OWN node in every resolution order. r3's deletion-drift
        // forward leg died loud here (the upper member greedily took the
        // lower node); under content binding that greed cannot happen.
        $file = $this->write('r4a.php', self::distinctContentTwins());
        $pair = $this->ifPairBlocks($file, unload: false); // [23,25] + [30,32], bodies +=1 / min()
        $this->mintHashes($file, $pair[0], $pair[1]);
        $pair[0]->unloadAst();
        $pair[1]->unloadAst();
        self::assertNotSame('', $pair[0]->structuralHash);
        self::assertNotSame($pair[0]->structuralHash, $pair[1]->structuralHash,
            'the fixtures\' twins are deliberately different content');

        $this->deleteFileLines($file, 8, 15);       // truths [8,10] + [15,17]

        foreach (['forward', 'reverse'] as $label) {
            [$m1, $m2] = $this->ifPairBlocks($file, [[23, 25], [30, 32]], unload: false);
            $this->mintHashes($file, $m1, $m2);
            $m1->unloadAst();
            $m2->unloadAst();
            $loader = $this->loaderWithConfig($file);
            if ($label === 'reverse') {
                $loader->resolve($m2);
                $loader->resolve($m1);
            } else {
                $loader->resolve($m1);              // forward — r3 was LOUD here
                $loader->resolve($m2);
            }
            self::assertSame([8, 10], [$m1->range->start, $m1->range->end], "$label leg: m1 own twin");
            self::assertSame([15, 17], [$m2->range->start, $m2->range->end], "$label leg: m2 own twin");
        }
    }

    public function testDecoyPoolNeverServesStrangersToMembers(): void
    {
        // Reviewer fork5 shape: two 4-line list-assign members with
        // IDENTICAL content (one class) and 4-line echo decoys wedged
        // between them — the decoys score nearer to the stale hints than
        // either twin and won silently under r3\'s positional law.
        $file = $this->write('r4b.php', self::decoyGeometry());
        $probe = $this->decoyMembers($file);        // live [16,19] + [24,27]
        $this->mintHashes($file, $probe[0], $probe[1]);
        self::assertSame($probe[0]->structuralHash, $probe[1]->structuralHash);
        self::assertSame([[16, 19], [20, 23], [24, 27], [28, 31]], $this->ifRanges($file),
            'fixture geometry sanity: m1, d1, m2, d2');

        $this->deleteFileLines($file, 6, 10);       // truths m1 [6,9] m2 [14,17]

        // Forward per-block order: the upper hint\'s nearest twin is
        // m2\'s physical node; the lower member must then either pair
        // honestly or starve LOUD. What may NEVER happen is a decoy span
        // ([10,13]/[18,21]) landing in a member.
        [$m1, $m2] = $this->decoyMembers($file, [[16, 19], [24, 27]]);
        $this->mintHashes($file, $m1, $m2);
        $m1->unloadAst();
        $m2->unloadAst();
        $loud = null;
        try {
            $loader = $this->loaderWithConfig($file);
            $loader->resolve($m1);
            $loader->resolve($m2);
        } catch (\RuntimeException $e) {
            $loud = $e->getMessage();
        }
        if ($loud !== null) {
            self::assertStringContainsString('could not relocate', $loud);
            self::assertStringContainsString('kind if', $loud);
        }
        foreach ([[$m1->range->start, $m1->range->end], [$m2->range->start, $m2->range->end]] as $s) {
            self::assertNotSame([10, 13], $s, 'decoy d1 must never be served into a member slot');
            self::assertNotSame([18, 21], $s, 'decoy d2 must never be served into a member slot');
        }

        // Hint-descending order lands the honest pairing (monotone window
        // + content twins): loud-or-correct holds at this strength.
        [$n1, $n2] = $this->decoyMembers($file, [[16, 19], [24, 27]]);
        $this->mintHashes($file, $n1, $n2);
        $n1->unloadAst();
        $n2->unloadAst();
        $loader = $this->loaderWithConfig($file);
        $loader->resolve($n2);
        $loader->resolve($n1);
        self::assertSame([14, 17], [$n2->range->start, $n2->range->end]);
        self::assertSame([6, 9], [$n1->range->start, $n1->range->end]);
    }

    public function testUniqueContentTwinAnchorsEvenWhenStrangersScoreBetter(): void
    {
        // POSITIVE CONTROL [M-1]: a lone member whose stale hint scores a
        // same-span decoy strictly better than its content twin (distance
        // 4 vs 4, insertion bias on the decoy) — content binding must
        // still land it on a twin, never on the decoy.
        $file = $this->write('r4c.php', self::decoyGeometry());
        $probe = $this->decoyMembers($file);
        $this->mintHashes($file, $probe[0], $probe[1]);

        $this->deleteFileLines($file, 6, 10);       // truths [6,9] + [14,17]

        [$member] = $this->decoyMembers($file, [[16, 19]]);
        $this->mintHashes($file, $member);
        $member->unloadAst();
        $this->loaderWithConfig($file)->resolve($member);
        self::assertSame([14, 17], [$member->range->start, $member->range->end],
            'nearest content twin wins over the nearer-scored decoy');

        // And the pure-stranger door: a hint whose content has been
        // deleted outright starves loudly.
        file_put_contents($file, "<?php\nnamespace F1Land;\nfunction gone(int \$x): int {\n    return \$x;\n}\n");
        [$ghost] = $this->decoyMembersFreshNoParse($file, [[16, 19]]);
        $ghost->structuralHash = $probe[0]->structuralHash;
        $ghost->unloadAst();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not relocate/');
        $this->loaderWithConfig($file)->resolve($ghost);
    }

    /**
     * Vector-2 geometry with REAL hashes: an 8-line comment deletion
     * moves the different-content sibling exactly onto the stale hint.
     * r3 refused the steal only via claim bookkeeping (order-dependent);
     * the strict-side content gate refuses it unconditionally, so BOTH
     * member orders now anchor correctly and quietly.
     */
    public function testImposterOnStaleHintRefusedByStrictContentGate(): void
    {
        $file = $this->write('r4d.php', self::imposterGeometry());
        $pair = $this->ifPairBlocks($file, unload: false); // A [16,18] +=1, B [24,26] min()
        $this->mintHashes($file, $pair[0], $pair[1]);
        self::assertNotSame($pair[0]->structuralHash, $pair[1]->structuralHash);

        $this->deleteFileLines($file, 8, 8);        // A truth [8,10]; B lands on [16,18]

        foreach ([[0, 1], [1, 0]] as $label => $swap) {
            [$a, $b] = $this->ifPairBlocks($file, [[16, 18], [24, 26]], unload: false);
            $this->mintHashes($file, $a, $b);
            $a->unloadAst();
            $b->unloadAst();
            $loader = $this->loaderWithConfig($file);
            if ($swap) {
                $loader->resolve($b);
                $loader->resolve($a);
            } else {
                $loader->resolve($a);               // r3: this order stayed loud
                $loader->resolve($b);
            }
            self::assertSame([8, 10], [$a->range->start, $a->range->end], "A-first leg $label");
            self::assertSame([16, 18], [$b->range->start, $b->range->end], "B leg $label");
        }
    }

    // -------------------------------------------------------- sweep level

    public function testRebaseSweepPairsMembersToTheirContentTwins(): void
    {
        // The batch law the per-block greedy cannot reach: two
        // identical-content members + decoys — the DP sweep pairs them to
        // the live twins in order, no matter what the greedy would have
        // done. Ranges refresh while the lazy profile holds (no ast).
        $file = $this->write('r4e.php', self::decoyGeometry());
        $pair = $this->decoyMembers($file);         // live [16,19] + [24,27]
        $this->mintHashes($file, $pair[0], $pair[1]);
        [$m1, $m2] = $pair;
        $m1->unloadAst();
        $m2->unloadAst();

        $this->deleteFileLines($file, 6, 10);       // live truths [6,9] + [14,17]

        $cluster = new Cluster('Fdeadbeefdeadbeef', [$m1, $m2], 1.0, true);
        $this->loaderWithConfig($file)->rebaseClusters([$cluster]);

        self::assertSame([6, 9], [$m1->range->start, $m1->range->end]);
        self::assertSame([14, 17], [$m2->range->start, $m2->range->end]);
        self::assertTrue($m1->isAstUnloaded(), 'the sweep refreshes ranges only — lazy profile kept');
        self::assertTrue($m2->isAstUnloaded());
    }

    public function testRebaseSweepStarvedThirdMemberFailsLoud(): void
    {
        $file = $this->write('r4f.php', self::decoyGeometry());
        $pair = $this->decoyMembers($file);
        $this->mintHashes($file, $pair[0], $pair[1]);
        $ghost = clone $pair[1];                    // third member, same class, no live twin
        foreach ([$pair[0], $pair[1], $ghost] as $b) {
            $b->unloadAst();                        // the sweep collects unloaded members only
        }

        $this->deleteFileLines($file, 6, 10);

        $cluster = new Cluster('Ffeedfacefeedface1', [$pair[0], $pair[1], $ghost], 1.0, true);
        try {
            $this->loaderWithConfig($file)->rebaseClusters([$cluster]);
            self::fail('count law: three members, two content twins — must go loud');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('could not relocate', $e->getMessage());
            self::assertStringContainsString('kind if', $e->getMessage());
        }
    }

    // ------------------------------------------------- [m-2] freshness law

    public function testFreshGroupsAreProvenWithoutAnyHashing(): void
    {
        // The fast path itself: cold-shape members (hints == live spans)
        // covering EVERY live node of the key — CLASS-SCOPED span identity
        // (the [C-1] hardening: the proof runs per hash-class against the
        // live-block corpus, geometry alone no longer books) proves zero
        // drift, so the sweep consumes no content recomputation at all,
        // and resolve() may skip the strict-side [M-1] gate on the
        // certified key without ever guessing.
        $file = $this->write('r5a.php', self::twoTwinIfs());
        $pair = $this->ifPairBlocks($file, null, false);  // loaded, live hints
        $this->mintHashes($file, $pair[0], $pair[1]);
        self::assertSame($pair[0]->structuralHash, $pair[1]->structuralHash,
            'aggressive mode folds the literals: both twins share one class');
        [$m1, $m2] = $pair;
        $s1 = [$m1->range->start, $m1->range->end];
        $s2 = [$m2->range->start, $m2->range->end];
        $m1->unloadAst();
        $m2->unloadAst();

        $loader = $this->loaderWithConfig($file);
        // The members ARE the live corpus here (same objects, ranges and
        // hashes survive unloadAst): class buckets match ⇒ book fresh.
        $loader->rebaseClusters([new Cluster('F0123456789abcde', [$m1, $m2], 1.0, true)], [$m1, $m2]);

        $stats = $loader->rebaseStats();
        self::assertSame(1, $stats['keys']);
        self::assertSame(1, $stats['freshKeys'], 'fully-clustered class-exact key must be booked fresh');
        self::assertSame(0, $stats['hashes'], 'the freshness proof must not hash a single candidate');
        self::assertSame($s1, [$m1->range->start, $m1->range->end], 'class-exact ⇒ nothing to refresh');
        self::assertSame($s2, [$m2->range->start, $m2->range->end]);

        $n1 = $loader->resolve($m1);
        $n2 = $loader->resolve($m2);
        self::assertNotNull($n1);
        self::assertNotNull($n2);
        self::assertSame($n1, $m1->ast);
        self::assertSame(0, $loader->rebaseStats()['hashes'],
            'strict hits on certified keys skip the content gate — identity, not guess');
    }

    public function testDecoyCountMismatchEscalatesWithRealHashing(): void
    {
        // Rejection side of the same proof: two members among two
        // same-scope strangers ⇒ counts disagree ⇒ the FULL content law
        // runs (and costs) — cold spans simply pass through the DP.
        $file = $this->write('r5b.php', self::decoyGeometry());
        $pair = $this->decoyMembers($file);
        $this->mintHashes($file, $pair[0], $pair[1]);
        [$m1, $m2] = $pair;
        $m1->unloadAst();
        $m2->unloadAst();

        // The live corpus holds TWO more same-key blocks (the echo-if
        // decoys): orphan hash-classes in the live map defeat the class
        // count check even though the member hints match their own spans.
        $decoys = array_values(array_filter(
            $this->allIfBlocks($file),
            static fn (Block $b): bool => !in_array([$b->range->start, $b->range->end], [[16, 19], [24, 27]], true)
        ));
        self::assertCount(2, $decoys, 'the two decoy guards must be carriers too');
        $this->mintHashes($file, ...$decoys);

        $loader = $this->loaderWithConfig($file);
        $loader->rebaseClusters(
            [new Cluster('Fabcdef0123456789', [$m1, $m2], 1.0, true)],
            array_merge([$m1, $m2], $decoys)
        );

        $stats = $loader->rebaseStats();
        self::assertSame(0, $stats['freshKeys'], 'a key with decoy blocks can never be proven fresh');
        self::assertGreaterThan(0, $stats['hashes'], 'escalation must pay the content law');
        self::assertSame([16, 19], [$m1->range->start, $m1->range->end]);
        self::assertSame([24, 27], [$m2->range->start, $m2->range->end]);
    }

    public function testDriftedKeyFailsFreshnessAndAnchorsOnTruth(): void
    {
        // Drift breaks the proof even with equal counts: count-match holds
        // (two members, two live nodes) but spans disagree ⇒ escalate ⇒
        // twins pair onto the moved truth.
        $file = $this->write('r5c.php', self::twoTwinIfs());
        $pair = $this->ifPairBlocks($file, null, false);
        $this->mintHashes($file, $pair[0], $pair[1]);
        [$m1, $m2] = $pair;
        $before = [$m1->range->start, $m2->range->start];
        $m1->unloadAst();
        $m2->unloadAst();

        $this->insertAfterOpen($file, 2); // both shift +2 — hints now stale

        // Comment-only drift preserves both hashes (normalization ignores
        // comments): the live carriers are the SAME classes at shifted
        // spans, so the per-class SPAN leg — not the class-set leg —
        // defeats booking and the full law re-anchors.
        $carriers = $this->ifPairBlocks($file, null, false);
        $this->mintHashes($file, $carriers[0], $carriers[1]);

        $loader = $this->loaderWithConfig($file);
        $loader->rebaseClusters([new Cluster('F987654321fedcba', [$m1, $m2], 1.0, true)], $carriers);

        self::assertSame(0, $loader->rebaseStats()['freshKeys'], 'drift must defeat the proof');
        self::assertGreaterThan(0, $loader->rebaseStats()['hashes']);
        self::assertSame($before[0] + 2, $m1->range->start);
        self::assertSame($before[1] + 2, $m2->range->start);
    }

    public function testNearMissHintWithEqualCountsEscalates(): void
    {
        // A stale hint off by one line, counts intact: the pigeonhole
        // needs EVERY span exact — one near-miss escalates, and the DP
        // still lands both members on their live bodies.
        $file = $this->write('r5d.php', self::twoTwinIfs());
        $pair = $this->ifPairBlocks($file, null, false);
        $this->mintHashes($file, $pair[0], $pair[1]);
        [$m1, $m2] = $pair;
        $m1->unloadAst();
        $m2->unloadAst();

        // Capture the untouched live corpus BEFORE the hint is corrupted.
        $carriers = $this->ifPairBlocks($file, null, false);
        $this->mintHashes($file, $carriers[0], $carriers[1]);
        $m1->range = new LineRange($m1->range->start - 1, $m1->range->end - 1);

        $loader = $this->loaderWithConfig($file);
        $loader->rebaseClusters([new Cluster('F5555aaaa5555aaaa', [$m1, $m2], 1.0, true)], $carriers);

        self::assertSame(0, $loader->rebaseStats()['freshKeys']);
        self::assertGreaterThan(0, $loader->rebaseStats()['hashes']);
        self::assertSame(9, $m1->range->start, 'DP restores the member onto its own live body');
        self::assertSame([13, 15], [$m2->range->start, $m2->range->end]);
    }

    public function testTrustedFreshScanServesWhatTheRunMintedWithoutProofs(): void
    {
        // [m-2] run-provenance arm of the fast path. RefactorStage
        // announces trust on every NON-replayed run: positions and hashes
        // are then this process's own mint, and policing them is pure
        // waste. The same shrunken-hint block proves both faces: an
        // untrusted loader refuses the content-less strict hit and goes
        // loud (no live body matches the bogus hash), while the trusted
        // loader serves the run's own bytes at zero proof cost — trust
        // vouches for the block object exactly as the fresh scan does.
        $file = $this->write('r5e.php', self::decoyGeometry());
        $bogus = str_repeat('ab', 32);

        $plain = $this->decoyMembers($file);
        $plain[0]->structuralHash = $bogus;
        $plain[0]->unloadAst();
        $untrusted = $this->loaderWithConfig($file);
        try {
            $untrusted->resolve($plain[0]);
            self::fail('a hash no live body carries must starve loudly when untrusted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('could not relocate', $e->getMessage());
        }
        self::assertGreaterThan(0, $untrusted->rebaseStats()['hashes'], 'untrusted path still proofs');

        $trustedPair = $this->decoyMembers($file);
        $trustedPair[0]->structuralHash = $bogus;
        $trustedPair[0]->unloadAst();
        $trusted = $this->loaderWithConfig($file);
        $trusted->trustFreshScan();
        $trusted->rebaseClusters([new Cluster('F13579bdfcafebab', [$trustedPair[0]], 1.0, true)]);
        self::assertSame(['keys' => 0, 'freshKeys' => 0, 'hashes' => 0, 'memo' => 0, 'walks' => 0], $trusted->rebaseStats(),
            'a trusted run has nothing replayed — the sweep must be a no-op');

        $node = $trusted->resolve($trustedPair[0]);
        self::assertNotNull($node);
        self::assertSame([16, 19], [$trustedPair[0]->range->start, $trustedPair[0]->range->end],
            'trusted strict hit is served as identity, exactly as minted');
        self::assertSame(0, $trusted->rebaseStats()['hashes'], 'zero content proofs consumed');
    }

    // ------------------------------------ leg A: batched single-walk index

    /**
     * engine-031 / output-015 leg A acceptance: a file with B blocks is
     * walked ONCE per loader process. Eight blocks spanning seven groups
     * (two functions, three methods sharing a name across two classes,
     * an if, a foreach, a match) all resolve through one whole-file
     * index walk; the re-entry path and every pool consumer hit the same
     * memo, and a fresh loader proves the memo is per-instance (its own
     * walk budget starts at one, never zero-reused across processes).
     */
    public function testWholeFileIsWalkedOnceAcrossEveryBlockOfTheFile(): void
    {
        $file    = $this->write('b1.php', self::multiGroupFile());
        $members = $this->multiGroupMembers($file);
        self::assertCount(8, $members);
        foreach ($members as $b) {
            $b->unloadAst();
        }

        $loader = $this->loader();
        $served = [];
        foreach ($members as $i => $b) {
            $node = $loader->resolve($b);
            self::assertNotNull($node, "block $i must resolve");
            self::assertSame($b->ast, $node, "block $i must keep the attached identity");
            self::assertSame($b->range->start, $node->getStartLine());
            self::assertSame($b->range->end, $node->getEndLine());
            $served[$i] = $node;
        }
        self::assertSame(1, $loader->rebaseStats()['walks'],
            'eight blocks over one file — exactly one whole-file walk');

        // Re-entry over the claim ledger must serve the SAME node objects
        // from the SAME memo (identity, not reconstruction).
        foreach ($members as $b) {
            $b->unloadAst();
        }
        foreach ($members as $i => $b) {
            self::assertSame($served[$i], $loader->resolve($b), "block $i memo identity");
        }
        self::assertSame(1, $loader->rebaseStats()['walks'],
            'the re-entry path adds zero walks — batched index memo');

        // Per-loader scope: a fresh process is a fresh walk budget.
        $second = $this->loader();
        $again  = $this->multiGroupMembers($file);
        $again[0]->unloadAst();
        self::assertNotNull($second->resolve($again[0]));
        self::assertSame(1, $second->rebaseStats()['walks'], 'the index memo is per instance');
    }

    /**
     * The re-anchor path shares the same single walk: two stale-hint
     * twin members both land honestly (pure +2 insertion drift) while
     * the loader performs ONE whole-file traversal — pre-leg-A this
     * geometry paid a strict walk plus a pool walk per member.
     */
    public function testReanchoredResolutionsShareTheSingleIndexWalk(): void
    {
        $file = $this->write('b2.php', self::twoTwinIfs());
        $pair = $this->ifPairBlocks($file, null, false);
        [$upHint, $lowHint] = $pair;
        $hints = [[$upHint->range->start, $upHint->range->end], [$lowHint->range->start, $lowHint->range->end]];

        $this->insertAfterOpen($file, 2); // truths move to [11,13] and [15,17]

        [$m1, $m2] = $this->freshIfPair($file, $hints);
        $loader    = $this->loader();
        $loader->resolve($m1);
        $loader->resolve($m2);

        self::assertSame([11, 13], [$m1->range->start, $m1->range->end]);
        self::assertSame([15, 17], [$m2->range->start, $m2->range->end]);
        self::assertSame(1, $loader->rebaseStats()['walks'],
            'two re-anchors over one file — the pool rides the same single walk');
        self::assertSame(0, $loader->rebaseStats()['hashes'], 'no config threaded, no content gate');
    }

    /**
     * Batched span collection on the escalation side: two positional
     * groups in one file both fail the freshness proof and escalate.
     * Pre-leg-A each key paid its own whole-file pool walk (and each
     * later resolve walked again); the batched index serves every pool
     * and every strict/re-entry lookup from ONE traversal while the
     * pairing law itself stays untouched.
     */
    public function testEscalationPoolsAreBatchedPerFileNotPerGroup(): void
    {
        $file  = $this->write('b3.php', self::splitScopeIfs());
        $upper = $this->ifBlockIn($file, 'Upper', 1);          // live [9,11]
        $lower = $this->blockOfKind($file, 'if', 'Lower', 1);  // outer [20,24]
        $this->mintHashes($file, $upper, $lower);
        $upper->unloadAst();
        $lower->unloadAst();

        $this->insertAfterOpen($file, 2); // truths [11,13] / [22,26] / [23,25]

        $carriers = [
            $this->ifBlockIn($file, 'Upper', 1),
            $this->blockOfKind($file, 'if', 'Lower', 1),
            $this->blockOfKind($file, 'if', 'Lower', 2),
        ];
        $this->mintHashes($file, ...$carriers);

        $cluster = new Cluster('Fb1b2b3b4b5b6b7b', [$upper, $lower], 1.0, true);
        $loader  = $this->loaderWithConfig($file);
        $loader->rebaseClusters([$cluster], $carriers);

        $stats = $loader->rebaseStats();
        self::assertSame(2, $stats['keys'], 'Upper and Lower keys both swept');
        self::assertSame(0, $stats['freshKeys'], 'drifted spans defeat every booking');
        self::assertGreaterThan(0, $stats['hashes'], 'escalation pays the content law');
        self::assertSame(1, $stats['walks'], 'two escalated pools, one batched walk');

        self::assertNotNull($loader->resolve($upper));
        self::assertNotNull($loader->resolve($lower));
        self::assertSame([11, 13], [$upper->range->start, $upper->range->end]);
        self::assertSame([22, 26], [$lower->range->start, $lower->range->end]);
        self::assertSame(1, $loader->rebaseStats()['walks'], 'resolution rides the same memo');
    }

    /**
     * The pool's scope law survived the move to batched collection: a
     * same-kind stranger in ANOTHER class that scores strictly nearer
     * the stale hint than the member's true node must never enter the
     * pool. (Discovered by this leg's mutation sweep — the pre-leg-A
     * suite never made the class filter decisive, so a mutant dropping
     * it passed everywhere; this pin closes that gap for good.)
     */
    public function testPoolScopeLawSurvivesTheBatchedIndex(): void
    {
        $file  = $this->write('b4.php', self::scopeSplitNearHint());
        $truth = $this->blockOfKind($file, 'if', 'Alpha', 1); // live [9,11]
        $member = new Block($file, new LineRange(17, 20), 'if', 'SG', 'Alpha', null, $truth->ast);
        $member->unloadAst();

        $loader = $this->loader();
        $node   = $loader->resolve($member);

        self::assertNotNull($node);
        self::assertSame([9, 11], [$node->getStartLine(), $node->getEndLine()]);
        self::assertSame([9, 11], [$member->range->start, $member->range->end],
            'the nearer-but-cross-class stranger must never be served');
        self::assertSame(1, $loader->rebaseStats()['walks'], 'one walk serves the whole re-anchor');
    }

    // ------------------------------------------------- candidate memo (leg B)

    /**
     * engine-031 / output-015 / output-040 leg B on the multi-class
     * rebase census (r6b geometry, three hash classes over one live pool):
     * the escalation asks every class the same question about every pool
     * candidate, so pre-memo the sweep paid clone + normalize + hash once
     * PER (class × candidate) — nine chains for three nodes. The
     * spl_object_id memo collapses it to one chain per distinct candidate
     * node; the later classes ride the stored hashes, and the pairing
     * outcomes stay bit-identical to the r6b pins.
     */
    public function testEscalationNormalizesEachLiveCandidateOnceAcrossClasses(): void
    {
        $file = $this->write('b5.php', self::crossScopeOrig());
        $orig = $this->allIfBlocks($file);
        [$i1, $i2, $i3] = $orig;
        $this->mintHashes($file, $i1, $i2, $i3);
        self::assertCount(3, array_unique([$i1->structuralHash, $i2->structuralHash, $i3->structuralHash]),
            'three distinct hash classes share the one positional key');
        $spans = array_map(static fn (Block $b): array => [$b->range->start, $b->range->end], $orig);
        foreach ($orig as $b) {
            $b->unloadAst();
        }

        file_put_contents($file, self::crossScopeSwapped());
        $live = $this->allIfBlocks($file);
        $this->mintHashes($file, ...$live);

        $loader = $this->loaderWithConfig($file);
        $loader->rebaseClusters([new Cluster('Fb5memo000000001', [$i1, $i2, $i3], 1.0, true)], $live);

        $stats = $loader->rebaseStats();
        self::assertSame(0, $stats['freshKeys'], 'the crossing must still escalate');
        self::assertSame(3, $stats['hashes'], 'O(L): three live candidates pay three chains, never classes × candidates');
        self::assertSame(6, $stats['memo'], 'the two later classes ride the memo on every candidate');
        self::assertSame($spans[0], [$i1->range->start, $i1->range->end], 'same rebase outcome: keep1 member stays home');
        self::assertSame($spans[2], [$i2->range->start, $i2->range->end], 'same rebase outcome: move2 follows its body');
        self::assertSame($spans[1], [$i3->range->start, $i3->range->end], 'same rebase outcome: move3 follows its body');

        $printer = new Standard();
        foreach ([[$i1, 'keep1'], [$i2, 'move2'], [$i3, 'move3']] as [$member, $marker]) {
            $node = $loader->resolve($member);
            self::assertNotNull($node);
            self::assertStringContainsString($marker, $printer->prettyPrint([$node]),
                "attached bytes must belong to the member itself ({$marker})");
        }
        self::assertSame(3, $loader->rebaseStats()['hashes'], 'the claim-ledger re-entry costs zero re-normalisation');
        self::assertSame(6, $loader->rebaseStats()['memo'], 'and it never touches the candidate memo either');
    }

    /**
     * The duplicate-hash-collision fixture answers the memo's keying law
     * from the resolve side: two live candidates share ONE structural hash
     * (the list-assign twins) and each is its own memo entry, while every
     * repeated decision — the second member's whole pool filter over the
     * four already-paid nodes — rides the memo. Match outcomes are the
     * honest forward pairing the pre-memo chain produced.
     */
    public function testMatchDecisionsOverTheDuplicateHashPoolAreMemoizedPerNode(): void
    {
        $file = $this->write('b6.php', self::decoyGeometry());
        $this->deleteFileLines($file, 6, 10);       // live truths [6,9] + [14,17]

        [$n2, $n1] = $this->decoyMembers($file, [[24, 27], [16, 19]]);
        $this->mintHashes($file, $n1, $n2);
        $n1->unloadAst();
        $n2->unloadAst();

        $loader = $this->loaderWithConfig($file);
        $loader->resolve($n2);
        $loader->resolve($n1);

        self::assertSame([14, 17], [$n2->range->start, $n2->range->end], 'same outcome as the pre-memo chain');
        self::assertSame([6, 9], [$n1->range->start, $n1->range->end]);

        $stats = $loader->rebaseStats();
        self::assertSame(4, $stats['hashes'], 'one chain per distinct live candidate — the twins keep two entries');
        self::assertSame(4, $stats['memo'], "the second member's four decisions all rode the memo");
    }

    /**
     * Memo scope law: like the batched index, the candidate memo is per
     * loader instance — a fresh process re-pays the same distinct-node
     * bill and reaches the same outcomes (the per-instance law leg A
     * pinned for walks, now pinned for re-normalisations).
     */
    public function testCandidateMemoIsScopedToTheLoaderInstance(): void
    {
        $file = $this->write('b7.php', self::decoyGeometry());
        $this->deleteFileLines($file, 6, 10);

        [$n2, $n1] = $this->decoyMembers($file, [[24, 27], [16, 19]]);
        $this->mintHashes($file, $n1, $n2);
        $n1->unloadAst();
        $n2->unloadAst();

        $first = $this->loaderWithConfig($file);
        $first->resolve($n2);
        $first->resolve($n1);

        [$m2, $m1] = $this->decoyMembers($file, [[24, 27], [16, 19]]);
        $this->mintHashes($file, $m1, $m2);
        $m1->unloadAst();
        $m2->unloadAst();

        $second = $this->loaderWithConfig($file);
        $second->resolve($m2);
        $second->resolve($m1);

        self::assertSame([14, 17], [$m2->range->start, $m2->range->end], 'identical outcomes');
        self::assertSame([6, 9], [$m1->range->start, $m1->range->end]);
        self::assertSame($first->rebaseStats()['hashes'], $second->rebaseStats()['hashes'],
            'a fresh loader carries a fresh candidate memo — no cross-process reuse');
        self::assertSame(4, $second->rebaseStats()['memo'], 'the per-instance bill repeats in full');
    }

    public function testCrossClassTranspositionEscalatesByClassBuckets(): void
    {
        // [C-1], the reviewer's attack: two DISTINCT-content guards trade
        // whole bodies while both stay extracted — every corpus token
        // survives (a legitimate cache HIT) and the span multiset is
        // slot-preserved, so the r5 geometry-only proof booked this key
        // and served each member the SIBLING's bytes silently. The
        // class-scoped proof sees every hash-class stranded at the other
        // slot, escalates to the full content law, and re-anchors both
        // members onto their own bodies — loud-or-correct, never silent.
        $file = $this->write('r6a.php', self::transposedGuardsOrig());
        [$a1, $a2] = $this->allIfBlocks($file);
        $this->mintHashes($file, $a1, $a2);
        self::assertNotSame($a1->structuralHash, $a2->structuralHash,
            'two distinct-content guards occupy two hash classes');
        $slot1 = [$a1->range->start, $a1->range->end];
        $slot2 = [$a2->range->start, $a2->range->end];
        $a1->unloadAst();
        $a2->unloadAst();

        file_put_contents($file, self::transposedGuardsSwapped());
        [$c1, $c2] = $this->allIfBlocks($file);
        $this->mintHashes($file, $c1, $c2);
        self::assertSame($slot1, [$c1->range->start, $c1->range->end], 'slots survive the swap');
        self::assertSame($slot2, [$c2->range->start, $c2->range->end]);
        self::assertSame($a2->structuralHash, $c1->structuralHash, 'the ONER body moved to slot 1');

        $loader = $this->loaderWithConfig($file);
        $loader->rebaseClusters([new Cluster('Fcafe000000000001', [$a1, $a2], 1.0, true)], [$c1, $c2]);

        $stats = $loader->rebaseStats();
        self::assertSame(0, $stats['freshKeys'], 'a transposition must never book fresh');
        self::assertGreaterThan(0, $stats['hashes'], 'escalation pays the content law');
        self::assertSame($slot2, [$a1->range->start, $a1->range->end], 'ALPHA member re-anchors onto ALPHA bytes');
        self::assertSame($slot1, [$a2->range->start, $a2->range->end], 'ONER member re-anchors onto ONER bytes');

        $printer = new Standard();
        $n1 = $loader->resolve($a1);
        $n2 = $loader->resolve($a2);
        self::assertNotNull($n1);
        self::assertNotNull($n2);
        $p1 = $printer->prettyPrint([$n1]);
        $p2 = $printer->prettyPrint([$n2]);
        self::assertStringContainsString('ALPHA', $p1, 'the attached AST IS the member’s own body');
        self::assertStringNotContainsString('ONER', $p1);
        self::assertStringContainsString('ONER', $p2);
        self::assertStringNotContainsString('ALPHA', $p2);
    }

    public function testCrossScopeTranspositionEscalatesByClassBuckets(): void
    {
        // Second geometry family (my [C-1] audit extension): a top-level
        // guard trades bodies with a same-kind guard NESTED inside a
        // function. if-blocks carry no scope-binding fields (name null,
        // class null, same namespace), so all three share ONE positional
        // group, and the 1-for-1 exchange preserves the span multiset —
        // pure geometry books it while the swapped classes are stranded.
        // Class buckets see the stranding and escalate; the full law
        // re-anchors all three guards onto their own bytes.
        $file = $this->write('r6b.php', self::crossScopeOrig());
        $orig = $this->allIfBlocks($file);
        self::assertCount(3, $orig);
        [$i1, $i2, $i3] = $orig;
        $this->mintHashes($file, $i1, $i2, $i3);
        self::assertNotSame($i2->structuralHash, $i3->structuralHash);
        $spans = array_map(static fn (Block $b): array => [$b->range->start, $b->range->end], $orig);
        foreach ($orig as $b) {
            $b->unloadAst();
        }

        file_put_contents($file, self::crossScopeSwapped());
        $live = $this->allIfBlocks($file);
        $this->mintHashes($file, ...$live);
        self::assertSame($spans, array_map(static fn (Block $b): array => [$b->range->start, $b->range->end], $live),
            'the positional span multiset survives untouched');

        $loader = $this->loaderWithConfig($file);
        $loader->rebaseClusters([new Cluster('Fcafe000000000002', [$i1, $i2, $i3], 1.0, true)], $live);

        $stats = $loader->rebaseStats();
        self::assertSame(0, $stats['freshKeys'], 'a cross-scope crossing must escalate');
        self::assertGreaterThan(0, $stats['hashes']);
        self::assertSame($spans[0], [$i1->range->start, $i1->range->end]);
        self::assertSame($spans[2], [$i2->range->start, $i2->range->end], 'the M2 member lands where the M2 body went');
        self::assertSame($spans[1], [$i3->range->start, $i3->range->end], 'the M3 member lands where the M3 body went');

        $printer = new Standard();
        foreach ([[$i1, 'keep1'], [$i2, 'move2'], [$i3, 'move3']] as [$member, $marker]) {
            $node = $loader->resolve($member);
            self::assertNotNull($node);
            self::assertStringContainsString($marker, $printer->prettyPrint([$node]),
                "attached bytes must belong to the member itself ({$marker})");
        }
    }

    public function testSameClassPermutationBooksFreshAndIsSpanHarmless(): void
    {
        // Semantics ruling (condition 4): aggressive normalization FOLDS
        // literals, so '$out += 1' and '$out += 2' guards are ONE hash
        // class. Exchanging their bodies keeps every class bucket
        // span-identical — booking fresh is CORRECT here, not a hole:
        // ApplyExtractor rewrites BY SPAN (rewriteMemberCallSite
        // :320-352 re-parses from disk; findNodeInStmts :357-380 matches
        // kind+range+name only), hole VALUES are read from the node AT
        // each slot, and generalizedAst/holes/holePaths come from the
        // resolve-ATTACHED ASTs — any bijection inside one hash class
        // yields identical signatures, hole tables, and patches; the
        // per-member rows stay set-equal. Only CROSS-class motion is
        // observable downstream, and that is exactly what buckets police.
        $file = $this->write('r6c.php', self::sameClassPermuteOrig());
        [$p1, $p2] = $this->allIfBlocks($file);
        $this->mintHashes($file, $p1, $p2);
        self::assertSame($p1->structuralHash, $p2->structuralHash, 'folded literals share one class');
        $s1 = [$p1->range->start, $p1->range->end];
        $s2 = [$p2->range->start, $p2->range->end];
        $p1->unloadAst();
        $p2->unloadAst();

        file_put_contents($file, self::sameClassPermuteSwapped()); // bytes differ, class identical
        [$c1, $c2] = $this->allIfBlocks($file);
        $this->mintHashes($file, $c1, $c2);

        $loader = $this->loaderWithConfig($file);
        $loader->rebaseClusters([new Cluster('Fcafe000000000003', [$p1, $p2], 1.0, true)], [$c1, $c2]);

        $stats = $loader->rebaseStats();
        self::assertSame(1, $stats['freshKeys'], 'within-class permutation is immaterial — booking stays lawful');
        self::assertSame(0, $stats['hashes']);
        self::assertSame($s1, [$p1->range->start, $p1->range->end]);
        self::assertSame($s2, [$p2->range->start, $p2->range->end]);
    }

    public function testWarmTranspositionAtCliLevelStaysOnTruth(): void
    {
        // End-to-end [C-1] replay through the real stages: the cold run
        // SAVES the cluster cache; a whole-statement transposition of
        // file1 preserves every corpus token, so the warm run genuinely
        // REPLAYS (asserted via clustersReplayed); the sweep — given the
        // live block corpus — must escalate and re-anchor both file1
        // members onto their own bodies, ids unmoved.
        $dir = $this->dir . '/r6cli';
        @mkdir($dir, 0o777, true);
        file_put_contents($dir . '/file1.php', self::corpswpFile1());
        file_put_contents($dir . '/file2.php', self::corpswpFile2());

        $config = new Config(paths: [$dir], exclude: [], minBlockSize: 1, cacheDir: $dir . '/cc');
        $cold = new PipelineState($config);
        (new ScanningStage())->run($cold, new NullOutput());
        (new PreprocessStage(false))->run($cold, new NullOutput());
        (new ClusterStage(false))->run($cold, new NullOutput());
        self::assertFalse($cold->clustersReplayed, 'a cold run must not claim a replay');
        self::assertCount(2, $cold->clusters);

        file_put_contents($dir . '/file1.php', self::corpswpFile1Swapped());
        $warm = new PipelineState($config);
        (new ScanningStage())->run($warm, new NullOutput());
        (new PreprocessStage(false))->run($warm, new NullOutput());
        (new ClusterStage(false))->run($warm, new NullOutput());
        self::assertTrue($warm->clustersReplayed, 'a token-preserving transposition must replay');

        $loader = new BlockAstLoader(new AstCache($dir . '/ast'), new AstParser(), $config);
        $loader->rebaseClusters($warm->clusters, $warm->blocks);
        self::assertSame(1, $loader->rebaseStats()['freshKeys'],
            'only the untouched file2 key books fresh; the transposing file1 key escalates');

        // CLUSTER-KEYED truth (never slot-relative — a slot-relative check
        // passes vacuously on the pre-fix bug, whose members are
        // self-consistently mislabelled). file2 never moved, so the cold
        // run names which id owns ALPHA bytes; after the swap the live
        // ALPHA body sits on the line containing 'ALPHA1', and the ALPHA
        // cluster's file1 member MUST cover exactly that line.
        $alphaLine = null;
        $onerLine = null;
        foreach (file($dir . '/file1.php') as $i => $line) {
            if ($alphaLine === null && str_contains($line, 'ALPHA1')) {
                $alphaLine = $i + 1;
            }
            if ($onerLine === null && str_contains($line, 'ONER1')) {
                $onerLine = $i + 1;
            }
        }
        self::assertNotNull($alphaLine);
        self::assertNotNull($onerLine);
        self::assertNotSame($alphaLine, $onerLine, 'the swap moved the bodies past each other');

        $f2AlphaLine = null;
        foreach (file($dir . '/file2.php') as $i => $line) {
            if ($f2AlphaLine === null && str_contains($line, 'ALPHA1')) {
                $f2AlphaLine = $i + 1;
            }
        }
        self::assertNotNull($f2AlphaLine, 'file2 (never swapped) keeps its ALPHA body');
        $alphaId = null;
        foreach ($cold->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                if (
                    str_ends_with($member->file, '/file2.php')
                    && $member->range->start <= $f2AlphaLine
                    && $f2AlphaLine <= $member->range->end
                ) {
                    $alphaId = $cluster->id;
                }
            }
        }
        self::assertNotNull($alphaId, 'cold run identifies the ALPHA cluster');

        $printer = new Standard();
        $seen = 0;
        foreach ($warm->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                if (!str_ends_with($member->file, '/file1.php')) {
                    continue;
                }
                ++$seen;
                $own = $cluster->id === $alphaId ? $alphaLine : $onerLine;
                self::assertTrue(
                    $member->range->start <= $own && $own <= $member->range->end,
                    'cluster ' . $cluster->id . ($cluster->id === $alphaId ? ' (ALPHA)' : ' (ONER)')
                    . ' must carry the file1 member covering live line ' . $own
                    . ', got [' . $member->range->start . ',' . $member->range->end . ']'
                );
                $node = $loader->resolve($member);
                self::assertNotNull($node);
                $body = $printer->prettyPrint([$node]);
                self::assertTrue(
                    str_contains($body, $cluster->id === $alphaId ? 'ALPHA' : 'ONER'),
                    'attached AST must be the members own body'
                );
            }
        }
        self::assertSame(2, $seen, 'both file1 members were relocated');
        $coldIds = array_map(static fn (Cluster $c): string => $c->id, $cold->clusters);
        $warmIds = array_map(static fn (Cluster $c): string => $c->id, $warm->clusters);
        sort($coldIds);
        sort($warmIds);
        self::assertSame($coldIds, $warmIds, 'content-derived ids are unmoved by the fix (and by the attack)');
    }

    /**
     * Every kind-'if' block in the file's CURRENT bytes, sorted by start,
     * built exactly like decoyMembers() so they share one positional
     * group key with the hand-built members.
     *
     * @return list<Block>
     */
    private function allIfBlocks(string $file, bool $unload = false): array
    {
        $stmts = (new AstParser())->parseFile($file) ?? [];
        $nodes = (new NodeFinder())->findInstanceOf($stmts, Node\Stmt\If_::class);
        usort($nodes, static fn (Node $a, Node $b): int => $a->getStartLine() <=> $b->getStartLine());
        $ns = null;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_ && $stmt->name !== null) {
                $ns = $stmt->name->toString();
                break;
            }
        }
        $blocks = [];
        foreach ($nodes as $node) {
            $block = new Block($file, new LineRange($node->getStartLine(), $node->getEndLine()), 'if', $ns, null, null, $node);
            if ($unload) {
                $block->unloadAst();
            }
            $blocks[] = $block;
        }
        return $blocks;
    }

    /**
     * The eight leg-A members: two functions, three methods (two sharing
     * one declared name across classes), one if, one foreach and one
     * match — every block scanned with extractor-faithful scope fields.
     *
     * @return list<Block>
     */
    private function multiGroupMembers(string $file): array
    {
        return [
            $this->functionBlock($file, 'alpha'),
            $this->functionBlock($file, 'beta'),
            $this->blockOfKind($file, 'method', 'First', 1, 'gate'),
            $this->blockOfKind($file, 'method', 'First', 1, 'handle'),
            $this->blockOfKind($file, 'method', 'Second', 1, 'gate'),
            $this->blockOfKind($file, 'if', null, 1),
            $this->blockOfKind($file, 'foreach', null, 1),
            $this->blockOfKind($file, 'match', 'Second', 1),
        ];
    }

    /**
     * The nth block of one kind whose enclosing class is exactly $class
     * (null = top-level/function scope) and — when $name is given — whose
     * declared name matches, taken in file (pre-)order. Built with the
     * scope fields the extractor itself would stamp: namespace and class
     * come from the real tree position via a hand-rolled pre-order walk
     * (anonymous classes use the '<anonymous>' marker convention), so no
     * geometry is guessed.
     */
    private function blockOfKind(string $file, string $kind, ?string $class, int $nth, ?string $name = null): Block
    {
        $nodeClass = match ($kind) {
            'if'      => Node\Stmt\If_::class,
            'foreach' => Node\Stmt\Foreach_::class,
            'match'   => Node\Expr\Match_::class,
            'method'  => Node\Stmt\ClassMethod::class,
            default   => throw new \InvalidArgumentException("kind $kind unsupported"),
        };
        $stmts  = $this->parser->parseFile($file) ?? [];
        $picked = [];
        $walk   = static function (array $nodes, ?string $ns, ?string $enc) use (&$walk, $nodeClass, $class, $name, &$picked): void {
            foreach ($nodes as $node) {
                if (!$node instanceof Node) {
                    continue;
                }
                $curNs  = $ns;
                $curEnc = $enc;
                if ($node instanceof Node\Stmt\Namespace_) {
                    $curNs = $node->name?->toString();
                } elseif ($node instanceof Node\Stmt\ClassLike) {
                    $curEnc = $node->name?->toString() ?? '<anonymous>';
                } elseif ($node instanceof $nodeClass) {
                    $named = $node instanceof Node\Stmt\ClassMethod ? $node->name->toString() : null;
                    if ($curEnc === $class && ($name === null || $named === $name)) {
                        $picked[] = [$node, $curNs];
                    }
                }
                foreach ($node->getSubNodeNames() as $prop) {
                    $child = $node->{$prop};
                    if (is_array($child)) {
                        $walk($child, $curNs, $curEnc);
                    } elseif ($child instanceof Node) {
                        $walk([$child], $curNs, $curEnc);
                    }
                }
            }
        };
        $walk($stmts, null, null);
        self::assertGreaterThanOrEqual($nth, count($picked), "fixture must hold at least $nth $kind block(s) in scope");
        [$node, $ns] = $picked[$nth - 1];
        return new Block($file, new LineRange($node->getStartLine(), $node->getEndLine()),
            $kind, $ns, $class, $name, $node);
    }

    private static function transposedGuardsOrig(): string
    {
        return <<<'PHP'
            <?php

            namespace TH2;

            $v = 7;
            $out = 0;
            if ($v > 0) {
                $out += strlen('ALPHA1');
                $out += strlen('ALPHA2');
            }
            if ($v < 0) {
                $out += strlen('ONER1');
                $out += strlen('ONER2');
            }
            echo $out;
            PHP;
    }

    private static function transposedGuardsSwapped(): string
    {
        return <<<'PHP'
            <?php

            namespace TH2;

            $v = 7;
            $out = 0;
            if ($v < 0) {
                $out += strlen('ONER1');
                $out += strlen('ONER2');
            }
            if ($v > 0) {
                $out += strlen('ALPHA1');
                $out += strlen('ALPHA2');
            }
            echo $out;
            PHP;
    }

    private static function crossScopeOrig(): string
    {
        return <<<'PHP'
            <?php

            namespace TH3;

            $x = 3;
            $out = 0;
            if ($x > 0) { echo 'keep1'; }

            if ($x > 1) { $out .= 'move2'; }

            function wrap(float $f): float
            {
                $y = $f;
                if ($x > 2) { $out[] = 'move3'; }
                return $y;
            }
            PHP;
    }

    private static function crossScopeSwapped(): string
    {
        return <<<'PHP'
            <?php

            namespace TH3;

            $x = 3;
            $out = 0;
            if ($x > 0) { echo 'keep1'; }

            if ($x > 2) { $out[] = 'move3'; }

            function wrap(float $f): float
            {
                $y = $f;
                if ($x > 1) { $out .= 'move2'; }
                return $y;
            }
            PHP;
    }

    /**
     * Leg-B multi-class collision geometry: TWO byte-identical guards
     * (one hash class — the duplicate-hash case the candidate memo must
     * key per NODE, never per hash) plus a different-class stranger, all
     * in one positional group. Ghost members hint far away so the sweep
     * always escalates onto the content law.
     */
    private static function memoMultiClassFile(): string
    {
        return <<<'PHP'
            <?php

            namespace MM1;

            $k = 1;
            $s = 0;
            if ($k > 1) { $s += 1; }

            if ($k > 1) { $s += 1; }

            if ($k > 2) { $s -= 1; }
            echo $s;
            PHP;
    }

    /**
     * Leg-B resolve-side geometry: two distinct-class guards, one
     * positional group, no twins — the stale-hint members force every
     * content decision (strict gate and pool filter alike) through the
     * same two live candidate nodes.
     */
    private static function memoBeltFile(): string
    {
        return <<<'PHP'
            <?php

            namespace MB1;

            $k = 1;
            $s = 0;
            if ($k > 1) {
                $s += strlen('ALPHA1');
            }
            if ($k < 1) {
                $s += strlen('ONER1');
                $s += strlen('ONER2');
            }
            echo $s;
            PHP;
    }

    private static function sameClassPermuteOrig(): string
    {
        return <<<'PHP'
            <?php

            namespace TH4;

            $x = 1;
            $out = 0;
            if ($x > 0) { $out += 1; }

            if ($x > 1) { $out += 2; }
            echo $out;
            PHP;
    }

    private static function sameClassPermuteSwapped(): string
    {
        return <<<'PHP'
            <?php

            namespace TH4;

            $x = 1;
            $out = 0;
            if ($x > 1) { $out += 1; }

            if ($x > 0) { $out += 2; }
            echo $out;
            PHP;
    }

    private static function corpswpFile1(): string
    {
        return <<<'PHP'
            <?php
            $v = (int)($argv[1] ?? 0);
            $out = 0;
            if ($v > 0) {
                $out += strlen('ALPHA1');
                $out += strlen('ALPHA2');
                $out += strlen('ALPHA3');
                $out += substr('ALPHA45', 1, 2) === 'LP' ? 1 : 0;
                $out += implode(',', ['ALPa', 'ALPb']) === 'ALPa,ALPb' ? 2 : 0;
            }
            if ($v < 0) {
                $out += strlen('ONER1');
                $out += strlen('ONER2');
                $out += strlen('ONER3');
                $out += substr('ONER45', 0, 3) === 'ONE' ? 1 : 0;
                $out += array_sum([5, 6, 7]) === 18 ? 2 : 0;
            }
            echo $out;
            PHP;
    }

    private static function corpswpFile2(): string
    {
        return self::corpswpFile1();
    }

    private static function corpswpFile1Swapped(): string
    {
        return <<<'PHP'
            <?php
            $v = (int)($argv[1] ?? 0);
            $out = 0;
            if ($v < 0) {
                $out += strlen('ONER1');
                $out += strlen('ONER2');
                $out += strlen('ONER3');
                $out += substr('ONER45', 0, 3) === 'ONE' ? 1 : 0;
                $out += array_sum([5, 6, 7]) === 18 ? 2 : 0;
            }
            if ($v > 0) {
                $out += strlen('ALPHA1');
                $out += strlen('ALPHA2');
                $out += strlen('ALPHA3');
                $out += substr('ALPHA45', 1, 2) === 'LP' ? 1 : 0;
                $out += implode(',', ['ALPa', 'ALPb']) === 'ALPa,ALPb' ? 2 : 0;
            }
            echo $out;
            PHP;
    }

    // ---------------------------------------------------------------- glue

    private function loader(): BlockAstLoader
    {
        return new BlockAstLoader(new AstCache($this->dir . '/astcache'), $this->parser);
    }

    /**
     * The two list-assign members of decoyGeometry (identified by their
     * `$x > 4` condition), with optional stale hint overrides.
     *
     * @param list<array{0:int,1:int}>|null $hints
     * @return list<Block>
     */
    private function decoyMembers(string $file, ?array $hints = null): array
    {
        $stmts = $this->parser->parseFile($file) ?? [];
        $ns    = $stmts[0] instanceof Node\Stmt\Namespace_ ? $stmts[0]->name?->toString() : null;
        $ifs   = (new NodeFinder())->find(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\If_
                && $n->cond instanceof Greater
                && $n->cond->right instanceof LNumber
                && $n->cond->right->value === 4
        );
        self::assertGreaterThanOrEqual(1, count($ifs));
        $out = [];
        foreach ($ifs as $i => $node) {
            $hint = $hints[$i] ?? [$node->getStartLine(), $node->getEndLine()];
            $out[] = new Block($file, new LineRange($hint[0], $hint[1]),
                'if', $ns, null, null, $node);
        }
        return $out;
    }

    /**
     * Member-block shells for a file whose live bytes no longer contain
     * them (ghost leg): hints only, carrier is a throwaway Nop.
     *
     * @param list<array{0:int,1:int}> $hints
     * @return list<Block>
     */
    private function decoyMembersFreshNoParse(string $file, array $hints): array
    {
        $out = [];
        foreach ($hints as [$s, $e]) {
            $out[] = new Block($file, new LineRange($s, $e), 'if', 'F1Land', null, null, new Node\Stmt\Nop());
        }
        return $out;
    }

    private function loaderWithConfig(string $file): BlockAstLoader
    {
        return new BlockAstLoader(
            new AstCache($this->dir . '/astcache'),
            $this->parser,
            Config::defaults([$file]),
        );
    }

    /**
     * Mint real structuralHashes through the SAME public recipe the
     * preprocessor uses (SSOT: PreprocessWorker.php:88-193) so the
     * loader's content gate binds against genuine values.
     *
     * @param Block ...$blocks blocks whose AST is still loaded
     */
    private function mintHashes(string $file, Block ...$blocks): void
    {
        $cfg  = Config::defaults([$file])->effectiveFor($file);
        $dbRegistry = ($cfg->dbSymbolsMethods === [] && $cfg->dbSymbolsFunctions === [])
            ? null
            : new DbOpRegistry(
                customMethodOps: $cfg->dbSymbolsMethods,
                customFunctionOps: $cfg->dbSymbolsFunctions,
            );
        $norm   = new Normalizer(
            mode: $cfg->normalizationMode,
            plugins: null,
            dbAware: $cfg->dbAware,
            dbOpRegistry: $dbRegistry,
            trinityCollapse: $cfg->trinityCollapse,
            lowMemory: false,
            nodePool: null,
        );
        $hasher = new SubtreeHasher();
        foreach ($blocks as $b) {
            $probe = new Block(
                $b->file, $b->range, $b->kind, $b->namespace, $b->class, $b->name,
                $b->ast ?? new Node\Stmt\Nop(),
            );
            $norm->normalize($probe);
            $b->structuralHash = $hasher->hash($probe->canonical);
        }
    }


    private function write(string $name, string $src): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $src);
        return $path;
    }

    /** Insert $n comment lines right after the `<?php` header line. */
    private function insertAfterOpen(string $file, int $n): void
    {
        $lines = explode("\n", (string) file_get_contents($file));
        $pad   = [];
        for ($i = 0; $i < $n; $i++) {
            $pad[] = '// drift ' . $i;
        }
        array_splice($lines, 1, 0, $pad);
        file_put_contents($file, implode("\n", $lines));
    }

    /** Delete $count lines starting at 1-based line $first. */
    private function deleteFileLines(string $file, int $first, int $count): void
    {
        $lines = explode("\n", (string) file_get_contents($file));
        array_splice($lines, $first - 1, $count);
        file_put_contents($file, implode("\n", $lines));
    }

    /**
     * Blocks whose class field carries the extractor's anonymous marker
     * (BlockExtractor.php:101 stores '<anonymous>' for anonymous classes).
     *
     * @return list<Block> every if-block in the file, in file order
     */
    private function anonymousIfBlocks(string $file): array
    {
        $stmts = $this->parser->parseFile($file) ?? [];
        $ns    = $stmts[0] instanceof Node\Stmt\Namespace_ ? $stmts[0]->name?->toString() : null;
        $ifs   = (new NodeFinder())->find(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\If_
        );
        $blocks = [];
        foreach ($ifs as $i) {
            $blocks[] = new Block($file, new LineRange($i->getStartLine(), $i->getEndLine()),
                'if', $ns, '<anonymous>', null, $i);
        }
        return $blocks;
    }

    /** @return list<Block> function-kind blocks (name, range) */
    private function functionBlock(string $file, string $name): Block
    {
        $stmts = $this->parser->parseFile($file) ?? [];
        $fn    = (new NodeFinder())->findFirst(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\Function_ && (string) $n->name === $name
        );
        self::assertInstanceOf(Node\Stmt\Function_::class, $fn);
        $ns = $stmts[0] instanceof Node\Stmt\Namespace_ ? $stmts[0]->name?->toString() : null;
        return new Block($file, new LineRange($fn->getStartLine(), $fn->getEndLine()),
            'function', $ns, null, $name, $fn);
    }

    /** @return array{0:Block,1:Block} the two twin-if blocks, in file order, AST loaded */
    private function ifBlocks(string $file): array
    {
        $stmts = $this->parser->parseFile($file) ?? [];
        $ns    = $stmts[0] instanceof Node\Stmt\Namespace_ ? $stmts[0]->name?->toString() : null;
        $ifs   = (new NodeFinder())->find(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\If_
        );
        self::assertCount(2, $ifs);
        $blocks = [];
        foreach ($ifs as $i) {
            $blocks[] = new Block($file, new LineRange($i->getStartLine(), $i->getEndLine()),
                'if', $ns, 'TwinHost', null, $i);
        }
        return $blocks;
    }

    /**
     * Unloaded if-blocks hinted at $hints (default: current live
     * positions) — fresh objects, so each scenario runs on a clean
     * claim ledger.
     *
     * @param list<array{0:int,1:int}>|null $hints
     * @return array{0:Block,1:Block}
     */
    private function freshIfPair(string $file, ?array $hints = null): array
    {
        return $this->ifPairBlocks($file, $hints, unload: true);
    }

    /**
     * @param list<array{0:int,1:int}>|null $hints
     * @return array{0:Block,1:Block}
     */
    private function ifPairBlocks(string $file, ?array $hints = null, bool $unload = true): array
    {
        [$a, $b] = $this->ifBlocks($file);
        $out = [];
        foreach ([$a, $b] as $i => $block) {
            $hint = $hints[$i] ?? [$block->range->start, $block->range->end];
            $fresh = new Block($file, new LineRange($hint[0], $hint[1]),
                'if', $block->namespace, $block->class, null, $block->ast ?? new Node\Stmt\Nop());
            if ($unload) {
                $fresh->unloadAst();
            }
            $out[] = $fresh;
        }
        /** @var array{0:Block,1:Block} $out */
        return $out;
    }

    private function ifBlockIn(string $file, string $class, int $nth): Block
    {
        $stmts = $this->parser->parseFile($file) ?? [];
        $ns    = $stmts[0] instanceof Node\Stmt\Namespace_ ? $stmts[0]->name?->toString() : null;
        $want  = null;
        $classNode = (new NodeFinder())->findFirst(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\Class_ && (string) $n->name === $class
        );
        self::assertNotNull($classNode, "class $class must exist in fixture");
        /** @var Node\Stmt\Class_ $classNode */
        foreach ($classNode->getMethods() as $m) {
            $found = (new NodeFinder())->find(
                $m->getStmts(),
                static fn (Node $n): bool => $n instanceof Node\Stmt\If_
            );
            if ($found !== []) {
                $want = $found[$nth - 1] ?? null;
                break;
            }
        }
        self::assertNotNull($want);
        return new Block($file, new LineRange($want->getStartLine(), $want->getEndLine()),
            'if', $ns, $class, null, $want);
    }

    /** @return list<array{0:int,1:int}> all if-block [start,end] pairs in order */
    private function ifRanges(string $file, ?string $classFilter = null): array
    {
        $stmts = $this->parser->parseFile($file) ?? [];
        if ($classFilter !== null) {
            $cls = (new NodeFinder())->findFirst(
                $stmts,
                static fn (Node $n): bool => $n instanceof Node\Stmt\Class_ && (string) $n->name === $classFilter
            );
            self::assertNotNull($cls);
            $stmts = [$cls];
        }
        $out = [];
        foreach ((new NodeFinder())->find($stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\If_) as $i) {
            $out[] = [$i->getStartLine(), $i->getEndLine()];
        }
        return $out;
    }

    // ----------------------------------------------------------- fixtures

    private static function oneFunction(): string
    {
        return <<<'PHP'
            <?php

            namespace TwinLand;

            function measure_alpha(array $rows): float
            {
                $sum = 0.0;
                foreach ($rows as $r) {
                    $sum += $r;
                }
                return $sum;
            }
            PHP;
    }

    private static function anonymousTwinIfs(): string
    {
        // Two anonymous classes (Block::$class = '<anonymous>' by the
        // extractor's marker convention), one structurally identical
        // if-block each — the exact shape of this loader's own visitor
        // pairs that a warm src/ replay exercises.
        return <<<'PHP'
            <?php

            namespace TwinLand;

            final class Host
            {
                public function run(): int
                {
                    $a = (new class {
                        public function gate(int $x): int
                        {
                            if ($x > 1) {
                                $x += 1;
                            }
                            return $x;
                        }
                    })->gate(1);
                    $b = (new class {
                        public function gate(int $x): int
                        {
                            if ($x > 2) {
                                $x += 2;
                            }
                            return $x;
                        }
                    })->gate(2);
                    return $a + $b;
                }
            }
            PHP;
    }

    private static function twoTwinIfs(): string
    {
        return <<<'PHP'
            <?php

            namespace TwinLand;

            class TwinHost
            {
                public function gate(int $x): int
                {
                    if ($x > 1) {
                        $x += 1;
                    }

                    if ($x > 2) {
                        $x += 2;
                    }
                    return $x;
                }
            }
            PHP;
    }

    /** if #1 at lines 23-25, if #2 at 30-32; 15 deletable fillers at 8-22. */
    private static function twinIfsWithTopFillers(): string
    {
        $fillers = '';
        for ($i = 1; $i <= 15; $i++) {
            $fillers .= sprintf("        // f%02d\n", $i);
        }
        $gap = '';
        for ($i = 1; $i <= 4; $i++) {
            $gap .= sprintf("            // g%02d\n", $i);
        }
        return "<?php\n\nnamespace TwinLand;\n\nclass TwinHost\n{\n"
            . "    public function gate(int \$x): int {\n"
            . $fillers
            . "        if (\$x > 1) {\n            \$x += 1;\n        }\n"
            . $gap
            . "        if (\$x > 2) {\n            \$x += 2;\n        }\n"
            . "        return \$x;\n    }\n}\n";
    }

    private static function splitScopeIfs(): string
    {
        return <<<'PHP'
            <?php

            namespace TwinLand;

            class Upper
            {
                public function gate(int $x): int
                {
                    if ($x > 1) {
                        $x += 1;
                    }
                    return $x;
                }
            }

            class Lower
            {
                public function gate(int $x): int
                {
                    if ($x > 9) {
                        if ($x > 10) {
                            $x += 3;
                        }
                    }
                    return $x;
                }
            }
            PHP;
    }

    /** if #1 at lines 16-18, if #2 at 24-26; 8 deletable fillers at 8-15. */
    private static function vector2Geometry(): string
    {
        $deleteme = '';
        for ($i = 1; $i <= 8; $i++) {
            $deleteme .= sprintf("        // dm%02d\n", $i);
        }
        $gap = '';
        for ($i = 1; $i <= 5; $i++) {
            $gap .= sprintf("            // gp%02d\n", $i);
        }
        return "<?php\n\nnamespace TwinLand;\n\nclass TwinHost\n{\n"
            . "    public function gate(int \$x): int {\n"
            . $deleteme
            . "        if (\$x > 1) {\n            \$x += 1;\n        }\n"
            . $gap
            . "        if (\$x > 2) {\n            \$x += 2;\n        }\n"
            . "        return \$x;\n    }\n}\n";
    }

    /**
     * twinIfsWithTopFillers geometry whose second body is a CALL, not an
     * increment — aggressive normalization folds literals but never
     * statement kinds, so these twins are genuinely different content
     * (asserted in-test).
     */
    private static function distinctContentTwins(): string
    {
        $fillers = '';
        for ($i = 1; $i <= 15; $i++) {
            $fillers .= sprintf("        // f%02d\n", $i);
        }
        $gap = '';
        for ($i = 1; $i <= 4; $i++) {
            $gap .= sprintf("            // g%02d\n", $i);
        }
        return "<?php\n\nnamespace TwinLand;\n\nclass TwinHost\n{\n"
            . "    public function gate(int \$x): int {\n"
            . $fillers
            . "        if (\$x > 1) {\n            \$x += 1;\n        }\n"
            . $gap
            . "        if (\$x > 1) {\n            \$x = min(\$x, 1);\n        }\n"
            . "        return \$x;\n    }\n}\n";
    }

    /** vector2Geometry with a structure-different stranger body. */
    private static function imposterGeometry(): string
    {
        $deleteme = '';
        for ($i = 1; $i <= 8; $i++) {
            $deleteme .= sprintf("        // dm%02d\n", $i);
        }
        $gap = '';
        for ($i = 1; $i <= 5; $i++) {
            $gap .= sprintf("            // gp%02d\n", $i);
        }
        return "<?php\n\nnamespace TwinLand;\n\nclass TwinHost\n{\n"
            . "    public function gate(int \$x): int {\n"
            . $deleteme
            . "        if (\$x > 1) {\n            \$x += 1;\n        }\n"
            . $gap
            . "        if (\$x > 1) {\n            \$x = min(\$x, 1);\n        }\n"
            . "        return \$x;\n    }\n}\n";
    }

    /**
     * Reviewer fork5 shape in function scope: two identical 4-line
     * list-assign members (class = one content value) with 4-line
     * echo decoys interleaved (lines 20-23 and 28-31) — same kind, same
     * scope, different content. Member geometry: 16-19 and 24-27.
     */
    private static function decoyGeometry(): string
    {
        $fillers = '';
        for ($i = 1; $i <= 10; $i++) {
            $fillers .= sprintf("    // f%02d\n", $i);
        }
        $member = "    if (\$x > 4) {\n        \$a = \$x * 2;\n        \$b = [\$a, \$x + 1];\n    }\n";
        return "<?php\n\nnamespace F1Land;\n\nfunction twin_gate(int \$x): int {\n"
            . $fillers
            . $member
            . "    if (\$x > 5) {\n        echo 'd1';\n        \$junk = 1;\n    }\n"
            . $member
            . "    if (\$x > 6) {\n        echo 'd2';\n        \$junk = 2;\n    }\n"
            . "    return \$x;\n}\n";
    }

    /**
     * Leg-A multi-group geometry: nine extractable blocks in one file —
     * two functions, three methods (First::gate and Second::gate share a
     * declared name across classes, so only the group key's class leg
     * keeps them apart), one if, two foreach loops and one match. Eight
     * of them serve as the leg-A members. Every kind here is a kindOf
     * arm, so the batched index must serve all groups from one walk.
     */
    private static function multiGroupFile(): string
    {
        return <<<'PHP'
            <?php

            namespace MultiLand;

            function alpha(array $rows): float
            {
                $sum = 0.0;
                foreach ($rows as $r) {
                    $sum += $r;
                }
                return $sum;
            }

            function beta(int $x): int
            {
                if ($x > 1) {
                    $x += 1;
                }
                return $x;
            }

            class First
            {
                public function gate(int $x): int
                {
                    return $x;
                }

                public function handle(array $a): int
                {
                    $c = 0;
                    foreach ($a as $v) {
                        $c += $v;
                    }
                    return $c;
                }
            }

            class Second
            {
                public function gate(int $y): int
                {
                    $z = match (true) {
                        $y > 5 => 1,
                        default => 0,
                    };
                    return $z;
                }
            }
            PHP;
    }

    /**
     * Scope-split geometry for the pool-scope pin: Alpha's if is the
     * member's truth at [9,11]; Beta's identical-shape if sits at
     * [20,22], deliberately NEARER the member's stale hint [17,20] than
     * the truth is, so only the class filter keeps the stranger out of
     * the re-anchor pool.
     */
    private static function scopeSplitNearHint(): string
    {
        return <<<'PHP'
            <?php

            namespace SG;

            class Alpha
            {
                public function a(int $x): int
                {
                    if ($x > 1) {
                        $x += 1;
                    }
                    return $x;
                }
            }

            class Beta
            {
                public function b(int $x): int
                {
                    if ($x > 3) {
                        $x += 3;
                    }
                    return $x;
                }
            }
            PHP;
    }
}
