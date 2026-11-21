<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Phpdup\Util\IdentifierIds;

/**
 * Item 1.1 (§5a): the strip helper is the single source of the
 * bag↔TED-label lockstep. These pins are its slot-safety contract:
 * numbered rename tokens collapse; every other synthetic name
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

    public function testStripIsIdempotent(): void
    {
        foreach (['Variable|v:__V9', 'Name|n:__CALL3', 'If_(', 'id:__MATCH__'] as $token) {
            $once = IdentifierIds::strip($token);
            $this->assertSame($once, IdentifierIds::strip($once));
        }
    }
}
