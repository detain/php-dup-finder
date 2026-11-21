<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Reporting\Severity;

/**
 * Pins for the severity center (php-dup-finder-output-037).
 *
 * The wire-label assertions below repeat the historical inline expressions of
 * SarifReporter/CheckstyleReporter verbatim on purpose: centralizing the
 * labels moved code, it must not move bytes.  Because the expected values are
 * literals independent of Severity, a mapping change goes red here even while
 * the reporters still agree with the center.
 */
final class SeverityTest extends TestCase
{
    public function testSarifLevelRoutesExactnessToHistoricalLabels(): void
    {
        // Pre-centralization SarifReporter:92: $cluster->exact ? 'warning' : 'note'
        self::assertSame('warning', Severity::sarifLevel(true));
        self::assertSame('note', Severity::sarifLevel(false));
    }

    public function testCheckstyleSeverityRoutesExactnessToHistoricalLabels(): void
    {
        // Pre-centralization CheckstyleReporter:41: $cluster->exact ? 'warning' : 'info'
        self::assertSame('warning', Severity::checkstyleSeverity(true));
        self::assertSame('info', Severity::checkstyleSeverity(false));
    }

    public function testSarifRuleDefaultLevelIsTheWarningTier(): void
    {
        // The rule-metadata default pre-centralization was the literal 'warning'.
        self::assertSame('warning', Severity::sarifRuleDefaultLevel());
        // It mirrors the exact tier: a result omitting its level should read
        // like phpdup's strongest class of finding.
        self::assertSame(Severity::sarifLevel(true), Severity::sarifRuleDefaultLevel());
    }

    public function testImpactScaleBoundariesUnchanged(): void
    {
        // Guards the tiers the other consumers (GitLab SAST, Graphviz) read:
        // >100 High, >=50 Medium, >=20 Low, else Info.
        self::assertSame('Info', Severity::forImpact(0));
        self::assertSame('Info', Severity::forImpact(19));
        self::assertSame('Low', Severity::forImpact(20));
        self::assertSame('Low', Severity::forImpact(49));
        self::assertSame('Medium', Severity::forImpact(50));
        self::assertSame('Medium', Severity::forImpact(100));
        self::assertSame('High', Severity::forImpact(101));
    }

    public function testScoreScaleBoundariesAndClampingUnchanged(): void
    {
        // >=0.85 High, >=0.65 Medium, else Low; out-of-range clamped.
        self::assertSame('Low', Severity::forScore(0.0));
        self::assertSame('Low', Severity::forScore(0.649));
        self::assertSame('Medium', Severity::forScore(0.65));
        self::assertSame('Medium', Severity::forScore(0.849));
        self::assertSame('High', Severity::forScore(0.85));
        self::assertSame('High', Severity::forScore(1.0));
        self::assertSame('High', Severity::forScore(1.5));
        self::assertSame('Low', Severity::forScore(-0.5));
    }
}
