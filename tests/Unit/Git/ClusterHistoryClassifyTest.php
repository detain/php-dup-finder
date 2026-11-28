<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Git;

use Phpdup\Cli\Config;
use Phpdup\Git\ClusterHistoryAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Item 4.2 — the pure verdict law.
 *
 * classify() is the whole taxonomy of the history leg with git removed:
 * recent_lone_fix > recently_diverged > in_sync > long_drifted, and
 * unmeasurable whenever the answer would be a guess. Every branch here
 * is a promise the JSON sidecar makes to report consumers, so each gets
 * a pin — including the degraded (log-less) mode, which may report dates
 * but must never fabricate a lone-commit claim.
 */
final class ClusterHistoryClassifyTest extends TestCase
{
    private const CUTOFF = 1_600_000_000;

    /**
     * @param list<array{file?: string, last: array{sha: string, time: int, msg: string}|null, lone: array{sha: string, time: int, subject: string, fix: bool}|null}> $members
     * @return array{verdict: string, reason: string|null}
     */
    private function verdict(array $members, bool $logAvailable = true, bool $activity = true): array
    {
        $times = [];
        foreach ($members as $member) {
            $times[] = $member['last']['time'] ?? null;
        }
        return ClusterHistoryAnalyzer::classify(
            ['members' => $members, 'activity' => $activity],
            $logAvailable,
            $times,
            self::CUTOFF,
        );
    }

    private static function last(string $sha = 'aa', int $time = 1_700_000_000): array
    {
        return ['sha' => $sha, 'time' => $time, 'msg' => 'm'];
    }

    public function testUncommittedMemberIsUnmeasurableNotAGuess(): void
    {
        $got = $this->verdict([
            ['last' => self::last(), 'lone' => null],
            ['last' => null, 'lone' => null],
        ]);
        self::assertSame('unmeasurable', $got['verdict']);
        self::assertSame('uncommitted_member', $got['reason']);
    }

    public function testNoWindowActivityIsLongDrifted(): void
    {
        $got = $this->verdict([
            ['last' => self::last('aa', 1_000_000_000), 'lone' => null],
            ['last' => self::last('bb', 1_100_000_000), 'lone' => null],
        ], activity: false);
        self::assertSame('long_drifted', $got['verdict']);
        self::assertNull($got['reason']);
    }

    public function testFixWordLoneCommitOutranksEverything(): void
    {
        $got = $this->verdict([
            ['last' => self::last(), 'lone' => ['sha' => 'cc', 'time' => 1_700_000_000, 'subject' => 'Fix CSRF token check', 'fix' => true]],
            ['last' => self::last('dd', 1_700_000_500), 'lone' => null],
        ]);
        self::assertSame('recent_lone_fix', $got['verdict']);
    }

    public function testLoneWithoutFixWordIsDivergence(): void
    {
        $got = $this->verdict([
            ['last' => self::last(), 'lone' => ['sha' => 'cc', 'time' => 1_700_000_000, 'subject' => 'Rename parameter', 'fix' => false]],
            ['last' => self::last('dd'), 'lone' => null],
        ]);
        self::assertSame('recently_diverged', $got['verdict']);
    }

    public function testActivityWithoutLoneIsInSync(): void
    {
        $got = $this->verdict([
            ['last' => self::last(), 'lone' => null],
            ['last' => self::last('dd'), 'lone' => null],
        ]);
        self::assertSame('in_sync', $got['verdict']);
    }

    public function testDegradedModeStraddlingDatesStillFlagDivergence(): void
    {
        $got = $this->verdict(
            [
                ['last' => self::last('aa', self::CUTOFF + 10), 'lone' => null],
                ['last' => self::last('bb', self::CUTOFF - 10), 'lone' => null],
            ],
            logAvailable: false,
            activity: true,
        );
        self::assertSame('recently_diverged', $got['verdict']);
        self::assertSame('dates_only_divergence', $got['reason']);
    }

    public function testDegradedModeNeverFabricatesLoneFix(): void
    {
        $got = $this->verdict(
            [
                ['last' => self::last('aa', self::CUTOFF + 10), 'lone' => null],
                ['last' => self::last('bb', self::CUTOFF + 20), 'lone' => null],
            ],
            logAvailable: false,
            activity: true,
        );
        self::assertSame('in_sync', $got['verdict'], 'both members live, no lone proof — absence of evidence must stay absence, not a claim');
    }

    public function testFixPatternMatchesTheDocWordSet(): void
    {
        foreach (['Fix null pointer', 'SECURITY: escape output', 'bug hotfix', 'patch'] as $i => $subject) {
            $want = $i < 3;
            self::assertSame($want, preg_match(ClusterHistoryAnalyzer::FIX_PATTERN, $subject) === 1, $subject);
        }
    }

    public function testConfigRejectsWindowBelowOneMonth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('history_window_months must be >= 1');
        new Config(paths: ['/x'], exclude: [], historyWindowMonths: 0);
    }

    public function testConfigDefaultsKeepTheUnarmedPosture(): void
    {
        $config = Config::defaults(['/x']);
        self::assertFalse($config->history);
        self::assertSame(12, $config->historyWindowMonths);
    }
}
