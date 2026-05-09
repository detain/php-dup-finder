<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use Phpdup\Pipeline\Stage;

final class StageTest extends TestCase
{
    public function testOrderedReturnsAllCasesInPipelineOrder(): void
    {
        $this->assertSame(
            [
                Stage::Scanning,
                Stage::Preprocessing,
                Stage::Clustering,
                Stage::Refactoring,
                Stage::Reporting,
            ],
            Stage::ordered(),
        );
    }

    public function testIndexMatchesOrderedPosition(): void
    {
        foreach (Stage::ordered() as $i => $stage) {
            $this->assertSame($i, $stage->index(), "index({$stage->value})");
        }
    }

    public function testTryFromAcceptsCanonicalLowercaseValues(): void
    {
        $this->assertSame(Stage::Scanning, Stage::tryFrom('scanning'));
        $this->assertSame(Stage::Preprocessing, Stage::tryFrom('preprocessing'));
        $this->assertSame(Stage::Reporting, Stage::tryFrom('reporting'));
        $this->assertNull(Stage::tryFrom('bogus'));
        $this->assertNull(Stage::tryFrom('Scanning'));
    }

    public function testLabelIsHumanReadable(): void
    {
        $this->assertSame('Scanning', Stage::Scanning->label());
        $this->assertSame('Refactoring', Stage::Refactoring->label());
    }
}
