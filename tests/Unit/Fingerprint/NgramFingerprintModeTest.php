<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Fingerprint;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\ContainmentSimilarity;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Util\IdentifierIds;

/**
 * Item 1.1 (§5a) — bag-side mode pins.
 *
 * Proves (1) the default is byte-identical to pre-knob behaviour,
 * (2) un-numbering bites on the doc's §3.3 family: when two blocks use
 * the same names but each name's FIRST-OCCURRENCE slot differs (the
 * numbering-cascade), the numbered token streams diverge at every
 * later position; stripping collapses them (doc .840 → 1.000 class),
 * and (3) the lowMemory compact bag path honours the mode like the hex
 * path.
 *
 * Fixture-shape notes (measured, see /tmp/opencode/lane11-1121/diag2.php):
 * - A plain consistent rename is a canonical NO-OP — the Normalizer
 *   numbers identifiers by first-occurrence slot, so divergence can
 *   only come from usage-pattern differences that re-slot names.
 * - J=1.000 under strip requires the un-numbered token streams to be
 *   literally identical: the prefix-cascade pair below achieves that —
 *   identical parameter order re-slotted by the extra tail statements.
 *   A plain head-inserted guard only reaches C=1.000 (different
 *   statement sets), which is the §3.3 row-2 class.
 */
final class NgramFingerprintModeTest extends TestCase
{
    private static function blockOf(string $code): \PhpParser\Node
    {
        $stmts = (new AstParser())->parseCode($code);
        self::assertNotNull($stmts, 'fixture code must parse');
        $blocks = (new BlockExtractor(minSize: 1))->extract('t.php', $stmts);
        self::assertNotEmpty($blocks, 'fixture must yield a block');
        (new Normalizer('aggressive'))->normalize($blocks[0]);
        return $blocks[0]->canonical;
    }

    /**
     * Exact-1.000 collapse pair: identical body, swapped parameter
     * declaration order, so each name's first-occurrence slot flips
     * between the blocks while the stripped streams coincide token for
     * token. Measured via /tmp/opencode/lane11-1121/diag2.php:
     * numbered J=0.1795 C=0.3043 bag-identical=0; un-numbered
     * J=1.0000 C=1.0000 bags byte-identical (both modes of hex and
     * lowMemory compact path).
     *
     * @return array{0: \PhpParser\Node, 1: \PhpParser\Node}
     */
    private static function cascadePair(): array
    {
        return [
            // $log first → __V1, $r second → __V2
            self::blockOf('<?php function s($log, $r) { $log->go($r); return $log->back($r); }'),
            // same body, param order swapped → slots re-dealt per position
            self::blockOf('<?php function s($r, $log) { $log->go($r); return $log->back($r); }'),
        ];
    }

    public function testDefaultModeIsByteIdenticalToLegacy(): void
    {
        [$small, $large] = self::cascadePair();
        $legacyL = (new NgramFingerprint(5))->fingerprint($large);
        $offL    = (new NgramFingerprint(5, false, false))->fingerprint($large);
        $this->assertSame($legacyL, $offL, 'numbered default and explicit numbered must not diverge');
        $this->assertNotSame(
            $legacyL,
            (new NgramFingerprint(5))->fingerprint($small),
            'non-vacuity: the numbered pair must still be distinguishable (no silent global strip)',
        );
    }

    public function testUnnumberedCollapsesRenumberCascade(): void
    {
        [$small, $large] = self::cascadePair();
        $jacNumbered   = (new JaccardSimilarity())->similarity(
            (new NgramFingerprint(5))->fingerprint($large),
            (new NgramFingerprint(5))->fingerprint($small),
        );
        $jacUnnumbered = (new JaccardSimilarity())->similarity(
            (new NgramFingerprint(5, false, true))->fingerprint($large),
            (new NgramFingerprint(5, false, true))->fingerprint($small),
        );
        $this->assertLessThan(1.0, $jacNumbered,
            'numbered bags must differ when slot assignment cascades through shared statements');
        $this->assertEqualsWithDelta(1.0, $jacUnnumbered, 0.0001,
            'un-numbered bags of a numbering-cascade pair must be identical (doc §3.3 class)');
    }

    public function testGuardDriftContainmentReachesOneInBothModes(): void
    {
        // improve_logic.md §3.3 row-2 class (a head guard shifts the
        // identifier usage pattern). Rewritten by engine-S02a: the
        // numbered divergence this fixture used to show (C .7391 via
        // diag2.php) was MANUFACTURED by two writer mechanisms the
        // sentinel redesign removed — (a) first-occurrence ordinals on
        // call names, so a head-guard insert cascaded every later
        // __CALL slot, and (b) the Param double-rename, which dealt
        // body uses a different slot than the declaration. With
        // name-stamped calls (folded to family tokens in BOTH modes,
        // since the stamp carries cross-block identity, not tier
        // information) and one slot per user variable, this pair's
        // bags are numbering-invariant: the un-guarded stripped bag is
        // now a window sub-multiset of the guarded one even numbered,
        // C = 1.0000 in both modes. The genuine numbered knob is still
        // pinned by testUnnumberedCollapsesRenumberCascade (variable
        // slot misalignment survives: numbered 0.1795 / un-numbered
        // 1.0000, values byte-identical to the pre-S02a measurements).
        $without = self::blockOf('<?php function s($log, $r) { $r->go($log); return $r->back($log); }');
        $withGuard = self::blockOf('<?php function s($log, $r) { $r->warn(); if (!$log) { return false; } $r->go($log); return $r->back($log); }');
        $c = new ContainmentSimilarity();
        $numberedC   = $c->similarity(
            (new NgramFingerprint(5))->fingerprint($withGuard),
            (new NgramFingerprint(5))->fingerprint($without),
        );
        $unnumberedC = $c->similarity(
            (new NgramFingerprint(5, false, true))->fingerprint($withGuard),
            (new NgramFingerprint(5, false, true))->fingerprint($without),
        );
        $this->assertEqualsWithDelta(1.0, $numberedC, 0.0001,
            'post-S02a the guard-drift class is numbering-invariant: sub-multiset holds numbered too');
        $this->assertEqualsWithDelta(1.0, $unnumberedC, 0.0001,
            'stripped bag of the smaller block must be a sub-multiset of the larger');
    }

    public function testLowMemoryCompactPathHonoursMode(): void
    {
        [$small, $large] = self::cascadePair();
        $compactNumbered   = (new NgramFingerprint(5, true))->fingerprint($large);
        $compactUnnumbered = (new NgramFingerprint(5, true, true))->fingerprint($large);
        $this->assertNotSame($compactNumbered, $compactUnnumbered,
            'compact bag must react to the mode like the hex bag');
        $jac = (new JaccardSimilarity())->similarity(
            (new NgramFingerprint(5, true, true))->fingerprint($large),
            (new NgramFingerprint(5, true, true))->fingerprint($small),
        );
        $this->assertEqualsWithDelta(1.0, $jac, 0.0001,
            'the compact path must collapse the cascade the same way');
    }

    public function testNumberedTokensAreTheStripTargets(): void
    {
        // Non-vacuity for the whole suite: real aggressively-normalised
        // tokens DO carry numbered identifiers, and the shared helper
        // collapses them idempotently — this is the one implementation
        // both bag and TED labels run through (lockstep by construction).
        $tokens = \Phpdup\Util\AstSerializer::tokens(self::blockOf(
            '<?php function z($s) { $s->alpha->send(1); $s->beta->send(2); }',
        ));
        $numbered = array_values(array_filter($tokens, static fn(string $t): bool => IdentifierIds::isNumbered($t)));
        $this->assertNotEmpty($numbered, 'fixture must produce numbered tokens for this probe to mean anything');
        foreach ($numbered as $token) {
            $stripped = IdentifierIds::strip($token);
            $this->assertNotSame($token, $stripped);
            $this->assertSame($stripped, IdentifierIds::strip($stripped), 'strip must be idempotent');
        }
    }
}
