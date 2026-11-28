<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Tui;

use PHPUnit\Framework\TestCase;

/**
 * TUI carrier guard (engine-S35a era incident class): report-shape
 * additions must stay invisible to the live terminal until the TUI is
 * deliberately taught them. Item 4.2 rides the same rule — the summary's
 * conditional `cluster_history` key and Report::$clusterHistory carrier
 * are report-side only; the TUI reads progress verbs, never payloads.
 *
 * A new consumer that legitimately reads a report field must update this
 * guard ON PURPOSE (name your field in $allowedReads below with a
 * one-line rationale), not by accident.
 */
final class TuiReportCarrierGuardTest extends TestCase
{
    /** Fields a TUI class may lawfully read off a Report/pipeline payload. */
    private const ALLOWED_REPORT_READS = [];

    /** Tokens that must never surface in src/Tui without an explicit ruling. */
    private const FORBIDDEN_TOKENS = ['clusterHistory', 'idiomCensus', '->report'];

    public function testTuiNeverReadsReportPayloadFields(): void
    {
        $files = $this->tuiSources();
        self::assertNotCount(0, $files, 'expected src/Tui/*.php sources to exist');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (in_array($token, self::ALLOWED_REPORT_READS, true)) {
                    continue;
                }
                self::assertStringNotContainsString(
                    $token,
                    $source,
                    sprintf('src/Tui carrier guard: %s must not read "%s" — report-shape fields stay invisible until the TUI is taught them on purpose (%s)', basename($file), $token, 'item 4.2 / engine-S35a incident class'),
                );
            }
        }
    }

    /**
     * The TUI pumps PipelineState only through typed messages/known
     * progress accessors — zero direct property reads today. Any new
     * `$state->field` access must be enumerated below on purpose.
     */
    public function testTuiProgressReadsAreTheWholeContract(): void
    {
        $stateReads = [];
        foreach ($this->tuiSources() as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match_all('/\$state->(\w+)/', $source, $m)) {
                foreach ($m[1] as $field) {
                    $stateReads[$field] = true;
                }
            }
        }
        $extra = array_values(array_diff(array_keys($stateReads), []));
        self::assertSame([], $extra, 'new PipelineState fields read by the TUI need an explicit guard update');
    }

    /** @return list<string> */
    private function tuiSources(): array
    {
        $root = dirname(__DIR__, 3) . '/src/Tui';
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);
        return $files;
    }
}
