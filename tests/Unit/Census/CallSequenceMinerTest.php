<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Census;

use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Census\CallSequenceMiner;

/**
 * Item 3.5 substrate (MINE-E): the ordered callee capture. One entry per
 * mined list, records as parallel names/lines/args lists in NodeFinder
 * document order. The chain semantics are the disclosed deviation: an
 * expression chain `a()->b()->c()` surfaces outside-IN (c, b, a) because
 * the visitor reaches the outermost MethodCall first — stable, ordered,
 * and identical run to run, which is all the sliding-window miner needs.
 */
final class CallSequenceMinerTest extends TestCase
{
    /** @param list<array{stmts: list<Node\Stmt>}> $lists */
    private static function mine(array $lists): array
    {
        return (new CallSequenceMiner())->mineFile($lists);
    }

    /** @return list<array{stmts: list<Node\Stmt>}> */
    private static function source(string $php): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast    = $parser->parse($php);
        self::assertIsArray($ast);
        return [['stmts' => array_values($ast)]];
    }

    public function testOrderedCalleesWithLines(): void
    {
        $records = self::mine(self::source(<<<'PHP'
            <?php
            $ch = curl_init();
            curl_setopt($ch, 1);
            $out = curl_exec($ch);
            PHP));
        self::assertCount(1, $records);
        self::assertSame(['curl_init', 'curl_setopt', 'curl_exec'], $records[0]['names']);
        self::assertSame([2, 3, 4], $records[0]['lines']);
        self::assertSame(2, $records[0]['firstLine']);
        self::assertSame(4, $records[0]['lastLine']);
    }

    public function testCalleeNameForms(): void
    {
        $records = self::mine(self::source(<<<'PHP'
            <?php
            $cart->total();
            Checkout::status();
            \App\Util::slug();
            helper_fn();
            PHP));
        self::assertSame(
            ['total', 'Checkout::status', 'App\Util::slug', 'helper_fn'],
            $records[0]['names'],
            'methods bare, statics Class::method (alias resolves to the written Name), funcs qualified — CallGraph::nameOf semantics verbatim',
        );
    }

    public function testDynamicCalleesAreNeverGuessed(): void
    {
        $records = self::mine(self::source(<<<'PHP'
            <?php
            $dyn();
            $obj->$m();
            $obj->{$f}();
            PHP));
        self::assertSame([], $records, 'a list of only-dynamic calls carries no named sequence');
    }

    public function testChainOrderIsOutsideInPreorder(): void
    {
        $records = self::mine(self::source(<<<'PHP'
            <?php
            $r = outer(inner(1))->chained(TOKEN_A)->last($x);
            PHP));
        self::assertSame(['last', 'chained', 'outer', 'inner'], $records[0]['names'],
            'the disclosed chain deviation: the visitor reaches the outermost call first');
    }

    public function testConstantArgumentTokens(): void
    {
        $records = self::mine(self::source(<<<'PHP'
            <?php
            fetch('https://api.example.com/v1', TIMEOUT_SECONDS, 42, $variable, [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_RETURNTRANSFER => true]);
            PHP));
        self::assertSame(['fetch'], $records[0]['names']);
        self::assertSame(
            [[
                'https://api.example.com/v1',
                'TIMEOUT_SECONDS',
                '42',
                'CURLOPT_SSL_VERIFYPEER=>false',
                'CURLOPT_RETURNTRANSFER=>true',
            ]],
            $records[0]['args'],
            'one witness bag per call: scalars direct, bareword constants by name, array literals as KEY=>value '
            . 'pairs; variable holes are simply absent (call-slot attribution happens in the census)',
        );
    }

    public function testEmptyStringArgumentsSpellAsQuotes(): void
    {
        // r1 CRITICAL-1 cure: '' as a call argument (the universal
        // preg_replace-replacement / implode-glue shape that aborted
        // armed runs over the repo's own src) is a REAL witness — it
        // spells as its own source form "''", never an empty token,
        // and is never dropped to a hole (which would erase the signal
        // that distinguishes implode('', $parts) from a ''-vs-',' split).
        $records = self::mine(self::source(<<<'PHP'
            <?php
            $reason = preg_replace('/^x/', '', $message);
            $blob = implode('', $parts);
            $out = render(['pad' => '', 'fill' => 'x']);
            PHP));
        self::assertSame(['preg_replace', 'implode', 'render'], $records[0]['names']);
        self::assertSame([['/^x/', "''"], ["''"], ["pad=>''", 'fill=>x']], $records[0]['args'],
            'direct empty arg and empty array values all spell as the two-quote token in place');
        foreach ($records as $record) {
            foreach ($record['args'] as $bag) {
                foreach ($bag as $token) {
                    self::assertNotSame('', $token, 'the miner never mints an empty token (census wire law)');
                }
            }
        }
    }

    public function testCallCapAndListCap(): void
    {
        $many = "<?php\n";
        for ($i = 0; $i < CallSequenceMiner::MAX_CALLS_PER_LIST + 10; $i++) {
            $many .= "tick_$i();\n";
        }
        $records = self::mine(self::source($many));
        self::assertCount(CallSequenceMiner::MAX_CALLS_PER_LIST, $records[0]['names'], 'calls per list capped (disclosed)');

        $parser = (new ParserFactory())->createForHostVersion();
        $lists  = [];
        for ($i = 0; $i < CallSequenceMiner::MAX_LISTS_PER_FILE + 5; $i++) {
            $ast     = $parser->parse("<?php\nsolo_$i();");
            $lists[] = ['stmts' => array_values((array) $ast)];
        }
        $records = self::mine($lists);
        self::assertCount(CallSequenceMiner::MAX_LISTS_PER_FILE, $records, 'lists per file capped (disclosed)');
    }

    public function testTokenCapAndByteClip(): void
    {
        $long    = 'https://long.example.com/' . str_repeat('x', 200);
        $args    = implode(', ', array_fill(0, CallSequenceMiner::MAX_ARG_TOKENS_PER_CALL + 4, "'$long'"));
        $records = self::mine(self::source("<?php\nwide($args);\n"));
        $tokens  = $records[0]['args'][0];
        self::assertCount(CallSequenceMiner::MAX_ARG_TOKENS_PER_CALL, $tokens, 'tokens per call capped');
        foreach ($tokens as $token) {
            // clip(): <= TOKEN_MAX_BYTES verbatim, else (TOKEN_MAX_BYTES-1)
            // bytes + the 3-byte U+2026 ellipsis.
            self::assertLessThanOrEqual(CallSequenceMiner::TOKEN_MAX_BYTES + 2, strlen($token));
        }
    }

    public function testLineGuardDropsJunkLists(): void
    {
        // Hand-built nodes without line attributes: start/end are -1, the
        // same guard every mined flavor applies.
        $nop = new Node\Stmt\Nop();
        self::assertSame([], self::mine([['stmts' => [$nop]]]));
        self::assertSame([], self::mine([['stmts' => []]]));
        self::assertSame([], self::mine([]));
    }
}
