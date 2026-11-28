<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use Phpdup\Cli\Config;
use Phpdup\Reporting\CliReporter;
use Phpdup\Reporting\Report;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * W2b's deferred CLI leg (landed in the rollup train): the vendored
 * demotion trailer line. Pins the stderr-line posture it honors — the
 * line renders ONLY when clusters were actually demoted: unarmed null and
 * scope-armed zero-shaped sections print nothing, so default CLI bytes
 * (and the CLI-adjacent belts) never move.
 */
final class CliReporterVendoredTrailerTest extends TestCase
{
    /** @param array<string,mixed>|null $section */
    private function render(?array $section): string
    {
        $out = new BufferedOutput();
        (new CliReporter())->render(
            new Report(0, 0, 0, [], Config::defaults([]), vendoredDuplicates: $section),
            $out,
        );
        return $out->fetch();
    }

    /** @return array<string,mixed> */
    private function section(int $clusters, int $lines, int $omitted): array
    {
        return ['clusters' => $clusters, 'lines' => $lines, 'omitted' => $omitted, 'rows' => []];
    }

    public function testUnarmedNullPrintsNoLine(): void
    {
        self::assertStringNotContainsString('vendored ', $this->render(null));
    }

    public function testZeroShapedArmedSectionPrintsNoLine(): void
    {
        // Scope-armed over a vendored-free corpus: measured zero, and the
        // trailer stays silent exactly like the stderr visibility line.
        self::assertStringNotContainsString('vendored ', $this->render($this->section(0, 0, 0)));
    }

    public function testDemotedSectionPrintsTheCounts(): void
    {
        $text = $this->render($this->section(2, 340, 0));
        self::assertStringContainsString('vendored  2 clusters · 340 lines demoted to the vendored_duplicates section', $text);
        self::assertStringNotContainsString('beyond the row cap', $text);
    }

    public function testRowCapOverflowIsNamed(): void
    {
        $text = $this->render($this->section(51, 990, 1));
        self::assertStringContainsString('vendored  51 clusters · 990 lines demoted', $text);
        self::assertStringContainsString(' · 1 beyond the row cap', $text);
    }
}
