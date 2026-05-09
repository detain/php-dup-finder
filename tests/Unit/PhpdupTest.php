<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Phpdup\Phpdup;
use Phpdup\Reporting\Report;

final class PhpdupTest extends TestCase
{
    public function testFluentBuilderProducesReportFromFixture(): void
    {
        $report = (new Phpdup())
            ->paths([__DIR__ . '/../Fixtures/notify'])
            ->minBlockSize(4)
            ->minImpact(0)
            ->noLazyAst()
            ->run();

        $this->assertInstanceOf(Report::class, $report);
        $this->assertGreaterThanOrEqual(1, count($report->clusters));
        $this->assertGreaterThanOrEqual(1, $report->files);
        $this->assertGreaterThanOrEqual(1, $report->blocks);
    }

    public function testSettersReturnNewInstanceForChainability(): void
    {
        $base = new Phpdup();
        $after = $base->paths(['/tmp/x'])->minBlockSize(4);
        $this->assertNotSame($base, $after, 'fluent setters must clone (immutable builder)');
    }

    public function testExactOnlySkipsApprox(): void
    {
        $report = (new Phpdup())
            ->paths([__DIR__ . '/../Fixtures/exact'])
            ->minBlockSize(4)
            ->minImpact(0)
            ->exactOnly()
            ->noLazyAst()
            ->run();
        foreach ($report->clusters as $c) {
            $this->assertTrue($c->exact, 'exact-only mode should yield exact-tagged clusters');
        }
    }
}
