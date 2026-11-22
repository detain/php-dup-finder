<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Phpdup\Util\IdentifierIds;

/**
 * Item 1.1 (§5a): the strip helper is the single source of the
 * bag↔TED-label lockstep. These pins are its slot-safety contract:
 * numbered rename tokens collapse, and — since the engine-S02a
 * redesign — so do the aggressive pass's name stamps on CALL/PROP/
 * CLASS (`__CALL_send`), while the variable branch stays ordinal-only.
 * Every other synthetic name (__FN/__STR/__MATCH__/__DB_*, keep-list,
 * $this) is a deliberate no-op, and literal content is only touched in
 * its rare tail form (documented, symmetric across bag and labels).
 *
 * Engine-S38 (engine-053) adds the equivalence layer: the regex-free
 * anchored-tail walk plus the bounded per-process memo must produce
 * EXACTLY the bytes the old PCRE pair produced — stream VALUES are the
 * fingerprint-stability contract, so the legacy patterns are replayed
 * here verbatim (legacyFoldStamps/legacyStrip) as the oracle over a
 * seeded random + adversarial corpus (N9N9 shapes, digits-only,
 * unicode, newline-anchored tails, embedded literals proving the
 * leftmost rule, and raw high-byte fuzz).
 */
final class IdentifierIdsTest extends TestCase
{
    /** Legacy PCRE forms (pre-engine-S38), kept verbatim as the oracle. */
    private const LEGACY_NUMBERED = '/__(V|CALL|PROP|CLASS)\d+$/';
    private const LEGACY_STAMPED = '/__(CALL|PROP|CLASS)_[A-Za-z0-9_\\\\]+$/';

    /** @return array<string,array{0:string,1:string}> */
    public static function stripProvider(): array
    {
        return [
            'numbered variable'      => ['Variable|v:__V12', 'Variable|v:__V'],
            'numbered call'          => ['Name|n:__CALL5', 'Name|n:__CALL'],
            'numbered method id'     => ['MethodCall|id:__CALL0', 'MethodCall|id:__CALL'],
            'numbered property id'   => ['PropertyFetch|id:__PROP2', 'PropertyFetch|id:__PROP'],
            'numbered class name'    => ['New_|n:__CLASS7', 'New_|n:__CLASS'],
            'zero counter'           => ['Variable|v:__V0', 'Variable|v:__V'],
            // engine-S02a name stamps (the redesign's live shapes)
            'stamped call'           => ['Name|n:__CALL_go', 'Name|n:__CALL'],
            'stamped method id'      => ['MethodCall|id:__CALL_send', 'MethodCall|id:__CALL'],
            'stamped underscore name' => ['Name|n:__CALL_do_thing', 'Name|n:__CALL'],
            'stamped property'       => ['PropertyFetch|id:__PROP_total', 'PropertyFetch|id:__PROP'],
            'stamped class bare'     => ['New_|n:__CLASS_Repo', 'New_|n:__CLASS'],
            'stamped class qualified' => ['New_|n:__CLASS_App\\Db', 'New_|n:__CLASS'],
            'stamped static prop'    => ['Expr|id:__PROP_count', 'Expr|id:__PROP'],
            'stamped digit-tail name' => ['Name|n:__CALL_save2', 'Name|n:__CALL'],
            // container labels / synoptics — suffix-less by construction
            'fn label untouched'     => ['Function_|id:__FN', 'Function_|id:__FN'],
            'str literal untouched'  => ['String_|s:__STR', 'String_|s:__STR'],
            'match synoptic kept'    => ['Expr|id:__MATCH__', 'Expr|id:__MATCH__'],
            'db op kept'             => ['Name|n:__DB_FIND__', 'Name|n:__DB_FIND__'],
            'keep-list name kept'    => ['Name|n:is_numeric', 'Name|n:is_numeric'],
            'this kept'              => ['Variable|v:this', 'Variable|v:this'],
            'plain type token'       => ['If_(', 'If_('],
            'digits not at tail'     => ['String_|s:__CALL5x', 'String_|s:__CALL5x'],
            'other underscore name'  => ['Name|n:_X2', 'Name|n:_X2'],
            // variable branch is ordinal-only: a user name that merely
            // looks stamped must not fold (stamps exist for names only)
            'var-shaped user name'   => ['Variable|v:__V_foo', 'Variable|v:__V_foo'],
            'stamped synthetic folds' => ['Name|n:__CALL___MATCH__', 'Name|n:__CALL'],
        ];
    }

    /** @dataProvider stripProvider */
    public function testStripTable(string $input, string $expected): void
    {
        $this->assertSame($expected, IdentifierIds::strip($input));
    }

    public function testIsNumberedProbe(): void
    {
        $this->assertTrue(IdentifierIds::isNumbered('Variable|v:__V1'));
        $this->assertTrue(IdentifierIds::isNumbered('Name|n:__CALL0'));
        $this->assertFalse(IdentifierIds::isNumbered('Variable|v:__V'));
        $this->assertFalse(IdentifierIds::isNumbered('String_|s:__CALL5x'));
    }

    /**
     * Engine-S02a: the stamp fold is mode-independent (both similarity
     * tiers consume it regardless of the counter knob), while counters
     * only fall in strip(). foldStamps must leave counters untouched.
     */
    public function testFoldStampsIsCounterBlind(): void
    {
        $this->assertSame('Name|n:__CALL', IdentifierIds::foldStamps('Name|n:__CALL_go'));
        $this->assertSame('New_|n:__CLASS', IdentifierIds::foldStamps('New_|n:__CLASS_App\\Db'));
        $this->assertSame('Variable|v:__V12', IdentifierIds::foldStamps('Variable|v:__V12'), 'counters must survive the stamp fold');
        $this->assertSame('id:__MATCH__', IdentifierIds::foldStamps('id:__MATCH__'));
        $this->assertSame(
            'Variable|v:__V_go',
            IdentifierIds::strip('Variable|v:__V_go'),
            'the variable branch is ordinal-only in both folds: a V name-stamp never exists and never materialises',
        );
        // strip over an already-stamp-folded label is the un-numbered
        // composition: counters go, nothing else moves; idempotent.
        $once = IdentifierIds::strip(IdentifierIds::foldStamps('Name|n:__CALL_save'));
        $this->assertSame('Name|n:__CALL', $once);
        $this->assertSame($once, IdentifierIds::strip($once));
    }

    public function testStripIsIdempotent(): void
    {
        foreach (['Variable|v:__V9', 'Name|n:__CALL3', 'If_(', 'id:__MATCH__', 'Name|n:__CALL_go', 'New_|n:__CLASS_App\\Db'] as $token) {
            $once = IdentifierIds::strip($token);
            $this->assertSame($once, IdentifierIds::strip($once));
        }
    }

    /**
     * Engine-S38 equivalence corpus: hand-picked adversarial tokens
     * (expected values were harvested from the legacy PCRE pair before
     * the rewrite) plus seeded random atom-composed and raw-byte
     * strings. Every row replays the token through the live class and
     * the legacy oracle, and through the memo (second call hits the
     * cache), asserting byte equality plus the fold/strip invariants
     * (idempotence, and strip == strip∘fold == fold∘strip — the
     * lockstep composition the class docblock promises).
     *
     * @return array<int,array{0:string,1:string,2:string,3:bool}> [token, legacy fold, legacy strip, legacy isNumbered]
     */
    public static function equivalenceProvider(): array
    {
        $rows = [];
        // token => [legacy fold, legacy strip], harvested from the pre-
        // rewrite PCRE pair; the isNumbered column is replayed live below
        // because its "$-before-final-newline" corner defeats hand-guessing.
        $handPicked = [
            ['__CALL__CALL_a', '__CALL', '__CALL'],
            ['__CALL5__CALL_a', '__CALL5__CALL', '__CALL5__CALL'],
            ['__CLASS__V', '__CLASS', '__CLASS'],
            ['__V__CALL9', '__V__CALL9', '__V__CALL'],
            ['__PROP12__CLASS34', '__PROP12__CLASS34', '__PROP12__CLASS'],
            ['__V1__V2', '__V1__V2', '__V1__V'],
            ['____V1', '____V1', '____V'],
            ['__CALL__', '__CALL', '__CALL'],
            ['__CALL_9', '__CALL', '__CALL'],
            ['__CALL_save2', '__CALL', '__CALL'],
            ['N9N9', 'N9N9', 'N9N9'],
            ['Name|n:__CALL9N9N9', 'Name|n:__CALL9N9N9', 'Name|n:__CALL9N9N9'],
            ['9912', '9912', '9912'],
            ['__V12345678901234567890', '__V12345678901234567890', '__V'],
            ['__V0', '__V0', '__V'],
            ['__V12', '__V12', '__V'],
            ['__V12é', '__V12é', '__V12é'],
            ['__CALL_aé', '__CALL_aé', '__CALL_aé'],
            ['__PROP_cøunt', '__PROP_cøunt', '__PROP_cøunt'],
            ["__V1\n", "__V1\n", "__V\n"],
            ["__CALL_save\n", "__CALL\n", "__CALL\n"],
            ["__V1\n\n", "__V1\n\n", "__V1\n\n"],
            ["__CALLa\n", "__CALLa\n", "__CALLa\n"],
            ["\n", "\n", "\n"],
            ['', '', ''],
            ['__V__', '__V__', '__V__'],
            ['__CALL_\\', '__CALL', '__CALL'],
            ['__PROP_a\\b', '__PROP', '__PROP'],
            ['Variable|v:__V_foo', 'Variable|v:__V_foo', 'Variable|v:__V_foo'],
            ['New_|n:__CLASS_App\\Db', 'New_|n:__CLASS', 'New_|n:__CLASS'],
            ['MethodCall|id:__CALL0', 'MethodCall|id:__CALL0', 'MethodCall|id:__CALL'],
            ['String_|s:__CALL5x', 'String_|s:__CALL5x', 'String_|s:__CALL5x'],
            ['id:__MATCH__', 'id:__MATCH__', 'id:__MATCH__'],
            ['Name|n:__DB_FIND__', 'Name|n:__DB_FIND__', 'Name|n:__DB_FIND__'],
            ['__CALL12', '__CALL12', '__CALL'],
            ['__CALL12__PROP34', '__CALL12__PROP34', '__CALL12__PROP'],
            ['x__CALL_a-b', 'x__CALL_a-b', 'x__CALL_a-b'],
            ['__DB_OP__V7', '__DB_OP__V7', '__DB_OP__V'],
        ];
        foreach ($handPicked as [$token, $legacyFold, $legacyStrip]) {
            $rows[] = [$token, $legacyFold, $legacyStrip, self::legacyIsNumbered($token)];
        }

        // Seeded atom corpus: sentinel fragments glued at random, the
        // shapes the live serializers actually emit plus their pathologies.
        $atoms = ['__V', '__CALL', '__PROP', '__CLASS', '__CALL_', '__PROP_', '__CLASS_',
                  '__', '_', '9', '0', '42', 'N', 'a', 'Z', 'z', '|', ':', '$', '\\', 'N9N9',
                  '__FN', '__STR', '__MATCH__', '__DB_FIND__', 'Variable|v:', 'Name|n:', 'é', "\n"];
        mt_srand(20261121);
        for ($i = 0; $i < 2500; $i++) {
            $token = '';
            for ($j = 0, $n = mt_rand(1, 7); $j < $n; $j++) {
                $token .= $atoms[mt_rand(0, count($atoms) - 1)];
            }
            if (mt_rand(0, 3) === 0) {
                $token .= "\n"; // the PCRE "$-before-final-newline" corner
            }
            $rows[] = [$token, self::legacyFoldStamps($token), self::legacyStrip($token), self::legacyIsNumbered($token)];
        }

        // Raw-byte corpus: any byte value, biased with sentinel fragments,
        // covering multibyte/unicode/control/NUL tails without assuming UTF-8.
        for ($i = 0; $i < 1500; $i++) {
            $token = '';
            for ($j = 0, $n = mt_rand(0, 20); $j < $n; $j++) {
                $token .= chr(mt_rand(0, 255));
            }
            $bias = mt_rand(0, 3);
            if ($bias === 0) {
                $token = '__CALL' . $token;
            } elseif ($bias === 1) {
                $token .= '__V' . mt_rand(0, 9);
            } elseif ($bias === 2) {
                $token = '__CLASS_' . $token;
            }
            $rows[] = [$token, self::legacyFoldStamps($token), self::legacyStrip($token), self::legacyIsNumbered($token)];
        }

        return $rows;
    }

    /** @dataProvider equivalenceProvider */
    public function testWalkAndMemoAreByteEqualToLegacyPcre(string $token, string $legacyFold, string $legacyStrip, bool $legacyNumbered): void
    {
        $fold = IdentifierIds::foldStamps($token);
        $this->assertSame($legacyFold, $fold, "foldStamps diverged from legacy PCRE for " . addcslashes($token, "\0..\37\177..\377"));
        $this->assertSame($legacyFold, IdentifierIds::foldStamps($token), 'memo-hit fold must equal first-sight fold');

        $strip = IdentifierIds::strip($token);
        $this->assertSame($legacyStrip, $strip, "strip diverged from legacy PCRE for " . addcslashes($token, "\0..\37\177..\377"));
        $this->assertSame($legacyStrip, IdentifierIds::strip($token), 'memo-hit strip must equal first-sight strip');

        $this->assertSame($legacyNumbered, IdentifierIds::isNumbered($token), 'isNumbered diverged for ' . addcslashes($token, "\0..\37\177..\377"));

        // Invariants that keep fingerprints stable across repeated folds.
        $this->assertSame($fold, IdentifierIds::foldStamps($fold), 'foldStamps must be idempotent');
        $this->assertSame($strip, IdentifierIds::strip($strip), 'strip must be idempotent');
        $this->assertSame($strip, IdentifierIds::strip($fold), 'fold-then-strip must equal strip (lockstep composition)');
        $this->assertSame($strip, IdentifierIds::foldStamps($strip), 'strip output must be stamp-stable');
    }

    /**
     * The memo is a bounded speed layer: a storm of distinct tokens must
     * reset it instead of growing it, and values must survive the reset.
     *
     * Fix-round r1 (review NIT-1): the entry cap is sampled at PEAK during
     * the storm, not only sampled after it — the old post-storm read missed
     * a >= → > off-by-one that lets the memo transiently hold cap+1
     * entries (r1 MUT3 came back GREEN).
     */
    public function testMemoStaysBoundedUnderStormAndKeepsValues(): void
    {
        $class = new \ReflectionClass(IdentifierIds::class);
        $entryCap = (int) $class->getConstant('MEMO_MAX_ENTRIES');
        $byteCap = (int) $class->getConstant('MEMO_MAX_BYTES');

        $tokens = [];
        for ($i = 0; $i < 3 * $entryCap; $i++) {
            $tokens[] = "Variable|v:__V{$i}_go";
        }
        $foldMemo = $class->getProperty('foldMemo');
        $peakEntries = 0;
        foreach ($tokens as $token) {
            $this->assertSame(self::legacyFoldStamps($token), IdentifierIds::foldStamps($token));
            $peakEntries = max($peakEntries, count($foldMemo->getValue()));
        }

        $foldBytes = $class->getProperty('foldMemoBytes');
        $this->assertLessThanOrEqual($entryCap, $peakEntries, 'fold memo peak during the storm must respect its entry cap, never transiently exceed it');
        $this->assertLessThanOrEqual($entryCap, count($foldMemo->getValue()), 'fold memo must reset at its entry cap, never grow past it');
        $this->assertLessThanOrEqual($byteCap + max(array_map('strlen', $tokens)), $foldBytes->getValue(), 'fold memo byte budget must bound storage');

        // After the reset cycles, every token still folds to the legacy bytes,
        // cached or not.
        foreach (['Variable|v:__V0_go', $tokens[13], $tokens[3 * $entryCap - 1]] as $token) {
            $this->assertSame(self::legacyFoldStamps($token), IdentifierIds::foldStamps($token));
        }
    }

    /**
     * Engine-S38 structural ratchet: the hot fold/strip path must stay
     * regex-free. A timing pin inside the suite would be scheduler-
     * sensitive (measured live: in-suite ratios swing 0.9x-1.6x for a
     * workload whose steady-state replay gain is only ~1.4x over the
     * PCRE-JIT legacy anyway); what must not rot is the design itself —
     * the discovery's per-call PCRE boot, once removed, must not come
     * back. The micro-bench numbers themselves are disclosed in
     * reviews/exec/engine-S38.impl.md (plus an end-to-end before/after
     * run), not asserted here.
     */
    public function testHotHelperCarriesNoPcre(): void
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass(IdentifierIds::class))->getFileName());
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue; // the docblock may narrate the legacy patterns; the code may not call them
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $this->assertStringNotContainsString('preg_', $code, 'IdentifierIds sits on the per-node x per-pair fold path: the regex-free walk of engine-S38 must not regress into per-call PCRE');
        // Belt: the pure walk result and the memoized entry point must be
        // the same function for the same bytes (no second divergent path).
        $token = 'Name|n:__CALL_save';
        $this->assertSame(self::legacyFoldStamps($token), IdentifierIds::foldStamps($token));
    }

    // ---- Legacy oracle: the pre-engine-S38 implementation, verbatim. ----

    private static function legacyStrip(string $token): string
    {
        $folded = preg_replace(self::LEGACY_STAMPED, '__$1', $token);
        if ($folded === null) {
            throw new \RuntimeException('identifier stamp fold failed for token: ' . $token);
        }

        $stripped = preg_replace(self::LEGACY_NUMBERED, '__$1', $folded);
        if ($stripped === null) {
            throw new \RuntimeException('identifier id strip failed for token: ' . $token);
        }

        return $stripped;
    }

    private static function legacyFoldStamps(string $token): string
    {
        $folded = preg_replace(self::LEGACY_STAMPED, '__$1', $token);
        if ($folded === null) {
            throw new \RuntimeException('identifier stamp fold failed for token: ' . $token);
        }

        return $folded;
    }

    private static function legacyIsNumbered(string $token): bool
    {
        return preg_match('/__(V|CALL|PROP|CLASS)\d+$/', $token) === 1;
    }
}
