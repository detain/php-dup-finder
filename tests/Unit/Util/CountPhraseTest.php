<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Phpdup\Util\CountPhrase;

/**
 * ui-grammar: the one shared count phrase — singular only at exactly one.
 */
final class CountPhraseTest extends TestCase
{
    public function testZeroIsPlural(): void
    {
        $this->assertSame('0 files', CountPhrase::files(0));
    }

    public function testOneSingularizes(): void
    {
        $this->assertSame('1 file', CountPhrase::files(1));
    }

    public function testTwoAndUpStayPlural(): void
    {
        $this->assertSame('2 files', CountPhrase::files(2));
        $this->assertSame('10 files', CountPhrase::files(10));
    }
}
