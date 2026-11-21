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
 * Every other synthetic name
 * (__FN/__STR/__MATCH__/__DB_*, keep-list, $this) is a deliberate
 * no-op, and literal content is only touched in its rare tail form
 * (documented, symmetric across bag and labels).
 */
final class IdentifierIdsTest extends TestCase
{
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
}
