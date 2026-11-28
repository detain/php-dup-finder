<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 8.2 bench leg — the per-class recall ROLLUP in bench/score-audit.php
 * (info-doc §6 item 3: recall "per class and overall"). Pinned with a
 * synthetic truth × report pair so the rollup math (verified-gate
 * admissions, tolerance matching, missed accounting, deterministic order)
 * cannot silently rot on a bench script no CI suite used to touch.
 */
final class ScoreAuditPerClassRollupTest extends TestCase
{
    public function testRollupAggregatesRangedObligationsPerClass(): void
    {
        $tmp = (string) sys_get_temp_dir() . '/phpdup-scoreaudit-' . uniqid('', true);
        mkdir($tmp, 0o775, true);
        $truth = $tmp . '/truth.json';
        $report = $tmp . '/report.json';
        $out = $tmp . '/score.md';

        file_put_contents($truth, json_encode([
            'schema' => 'audit-truth/1',
            'corpus' => 'synthetic-e82',
            'source_sha256' => str_repeat('a', 64),
            'findings' => [
                // clone: one tolerance-matched (10±tol vs 11), one missed.
                ['id' => 'B001', 'class' => 'clone', 'title' => 'A', 'members' => [['file' => 'a.php', 'start' => 10, 'end' => 20, 'verified' => true]]],
                ['id' => 'B003', 'class' => 'clone', 'title' => 'C', 'members' => [['file' => 'c.php', 'start' => 50, 'end' => 60, 'verified' => true]]],
                // retry: matched.
                ['id' => 'B002', 'class' => 'retry', 'title' => 'B', 'members' => [['file' => 'b.php', 'start' => 30, 'end' => 40, 'verified' => true]]],
                // sql: the verified:false token is NOT chargeable — the
                // obligation counts ONLY the admitted ranged member.
                ['id' => 'B004', 'class' => 'sql', 'title' => 'D', 'members' => [
                    ['file' => 'd.php', 'start' => 70, 'end' => 80, 'verified' => false],
                    ['file' => 'd.php', 'start' => 90, 'end' => 95, 'verified' => true],
                ]],
            ],
        ]));
        file_put_contents($report, json_encode([
            'schema_version' => '1.8',
            'clusters' => [
                ['id' => 'c0', 'members' => [['file' => '/root/a.php', 'start' => 11, 'end' => 20]]],
                ['id' => 'c1', 'members' => [['file' => '/root/b.php', 'start' => 30, 'end' => 40], ['file' => '/root/x.php', 'start' => 1, 'end' => 5]]],
                ['id' => 'c2', 'members' => [['file' => '/root/d.php', 'start' => 90, 'end' => 95]]],
            ],
        ]));

        $cmd = sprintf(
            '%s %s --truth=%s --report=%s --root=/root --out=%s 2>%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/bench/score-audit.php'),
            escapeshellarg($truth),
            escapeshellarg($report),
            escapeshellarg($out),
            escapeshellarg($tmp . '/err.txt'),
        );
        exec($cmd, $lines, $rc);
        try {
            $this->assertSame(0, $rc, 'score-audit leg failed: ' . implode("\n", $lines) . file_get_contents($tmp . '/err.txt'));
            $md = (string) file_get_contents($out);

            $this->assertStringContainsString('## Per-class recall rollup', $md);
            $this->assertMatchesRegularExpression('/^\| clone \| 2 \| 1 \| 1 \| 50\.0% \|$/m', $md, 'clone: 2 obligations, 1 matched, 1 missed');
            $this->assertMatchesRegularExpression('/^\| retry \| 1 \| 1 \| 0 \| 100\.0% \|$/m', $md);
            $this->assertMatchesRegularExpression('/^\| sql \| 1 \| 1 \| 0 \| 100\.0% \|$/m', $md, 'verified:false tokens never inflate a class obligation');
            $this->assertStringContainsString('| **overall** | **4** | **3** | **1** |', $md, 'overall row agrees with the totals block');
            // obligations DESC, class ASC on ties — deterministic nightly output.
            $this->assertLessThan(strpos($md, '| retry |'), strpos($md, '| clone |'), 'higher-obligation class leads');
            $this->assertLessThan(strpos($md, '| sql |'), strpos($md, '| retry |'), 'tied classes sort alphabetically');
        } finally {
            @unlink($truth);
            @unlink($report);
            @unlink($out);
            @unlink($tmp . '/err.txt');
            @rmdir($tmp);
        }
    }
}
