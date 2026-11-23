<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Reporting\ReportWriteException;
use Phpdup\Reporting\WritesReportFile;
use Phpdup\Testing\FuzzCorpusGenerator;

/**
 * Pins the output-S54 venue (php-dup-finder-once-040, output-024): every
 * sink in {@see WritesReportFile} either lands its bytes or raises the
 * named {@see ReportWriteException} — there is no path where a refused,
 * truncated, or uncreatable write returns quietly for a caller to print a
 * success line over. The historic class of defect: `@mkdir` + unchecked
 * `file_put_contents` claimed victory on a disk that took nothing.
 *
 * Hostile targets are crafted only in runtime scratch space (sys_get_temp_dir
 * under a per-test uniqid, chmod-restored in tearDown); the device-full case
 * rides /dev/full so it needs no root tricks. Where DAC is what makes a
 * target unwritable, the test skips for uid 0 (root bypasses permissions).
 *
 * @see WritesReportFile
 */
final class WritesReportFileTest extends TestCase
{
    /** @var list<string> scratch directories to sweep in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->sweep($dir);
        }
        $this->tmpDirs = [];
    }

    private function sweep(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                @chmod($entry, 0o700);
                $this->sweep($entry);
            } else {
                @unlink($entry);
            }
        }
        @chmod($dir, 0o700);
        @rmdir($dir);
    }

    private function scratch(string $suffix = ''): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-s54-' . getmypid() . '-' . uniqid('', true) . $suffix;
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    private function skipIfRoot(string $why): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses DAC: ' . $why);
        }
    }

    public function testWriteFileLandsEveryByteAndCreatesAbsentDirectories(): void
    {
        $sink  = new WritesReportFileSink();
        $deep  = $this->scratch('/a/b');
        $path  = $deep . '/report.json';

        $sink->store($path, '{"ok":true}');

        $this->assertFileExists($path);
        $this->assertSame('{"ok":true}', file_get_contents($path));

        // overwrite semantics: a second write replaces, never appends.
        $sink->store($path, '{"ok":false}');
        $this->assertSame('{"ok":false}', file_get_contents($path));
    }

    public function testRefusedWriteNamesTargetBytesAndDelta(): void
    {
        if (!is_writable('/dev/full')) {
            $this->markTestSkipped('no /dev/full on this host');
        }
        error_clear_last();
        $sink = new WritesReportFileSink();

        try {
            $sink->store('/dev/full', 'abcde');
            $this->fail('a write to /dev/full must not return quietly');
        } catch (ReportWriteException $failure) {
            $message = $failure->getMessage();
            $this->assertStringContainsString('writing /dev/full failed', $message);
            $this->assertStringContainsString('landed 0 of 5 bytes', $message);
            $this->assertStringContainsString('delta 5', $message);
            $this->assertStringContainsString('No space left on device', $message);
        }

        // The scoped handler consumed the diagnostic: it must not be left
        // riding in global error state for some later arm to misattribute.
        $last = error_get_last();
        $this->assertNull($last, 'failed write leaked into error_get_last()');
    }

    public function testTruncatedStreamIsReportedNotClaimed(): void
    {
        if (!in_array('shortsink', stream_get_wrappers(), true)) {
            stream_wrapper_register('shortsink', ShortSink::class);
        }

        $sink = new WritesReportFileSink();
        try {
            $sink->store('shortsink://truncated', '0123456789');
            $this->fail('a short write must not return quietly');
        } catch (ReportWriteException $failure) {
            $message = $failure->getMessage();
            $this->assertStringContainsString('writing shortsink://truncated failed', $message);
            // PHP's file_put_contents reports partial completion as false —
            // the diagnostic clause carries the stream's own accounting.
            $this->assertStringContainsString('bytes written', $message);
            $this->assertStringContainsString('delta 10', $message);
        }
    }

    public function testUnwritableDirectoryFailsLoudInsteadOfSucceedingQuietly(): void
    {
        $this->skipIfRoot('a chmod-0500 directory stays writable for root');
        $dir = $this->scratch('/locked');
        mkdir($dir, 0o775, true);
        chmod($dir, 0o500);

        $sink = new WritesReportFileSink();
        try {
            $sink->store($dir . '/report.json', '{}');
            $this->fail('writing into an unwritable directory must throw');
        } catch (ReportWriteException $failure) {
            $this->assertStringContainsString('writing ' . $dir . '/report.json failed', $failure->getMessage());
            $this->assertStringContainsString('Permission denied', $failure->getMessage());
        } finally {
            chmod($dir, 0o700);
        }
    }

    public function testUncreatableDirectoryNamesTheDirectoryAndTheReason(): void
    {
        $this->skipIfRoot('a chmod-0500 parent still admits mkdir for root');
        $parent = $this->scratch('/ro-parent');
        mkdir($parent, 0o775, true);
        chmod($parent, 0o500);

        $sink = new WritesReportFileSink();
        $target = $parent . '/deeper';
        try {
            $sink->makeRoom($target);
            $this->fail('mkdir into an unwritable parent must throw');
        } catch (ReportWriteException $failure) {
            $this->assertStringContainsString('creating directory ' . $target . ' failed', $failure->getMessage());
            $this->assertStringContainsString('Permission denied', $failure->getMessage());
        }

        try {
            // Through the write leg the same refusal carries the missing
            // directory's name — the write never proceeds to pretend
            // success over a tree that was never made.
            $sink->store($parent . '/nested/report.json', '{}');
            $this->fail('a write behind an uncreatable directory must throw');
        } catch (ReportWriteException $failure) {
            $this->assertStringContainsString(
                'creating directory ' . $parent . '/nested failed',
                $failure->getMessage()
            );
        } finally {
            chmod($parent, 0o700);
        }
    }

    public function testEnsureDirectoryIsNoopForExistingPresentAndEmptyPaths(): void
    {
        $sink = new WritesReportFileSink();
        $dir  = $this->scratch('/exists');
        mkdir($dir, 0o775, true);

        $sink->makeRoom($dir);   // exists → untouched
        $sink->makeRoom('');     // dirname('file') === '' → no-op
        $this->assertDirectoryExists($dir);
    }

    public function testAppendFileAddsAndChecksEveryLine(): void
    {
        if (!is_writable('/dev/full')) {
            $this->markTestSkipped('no /dev/full on this host');
        }
        $sink = new WritesReportFileSink();
        $file = $this->scratch('/ledger.jsonl');

        $sink->addLine($file, "first\n");
        $sink->addLine($file, "second\n");
        $this->assertSame("first\nsecond\n", file_get_contents($file));

        try {
            $sink->addLine('/dev/full', "third\n");
            $this->fail('appending to /dev/full must not return quietly');
        } catch (ReportWriteException $failure) {
            $this->assertStringContainsString('appending /dev/full failed', $failure->getMessage());
            $this->assertStringContainsString('landed 0 of 6 bytes', $failure->getMessage());
        }
    }

    public function testFuzzCorpusManifestIsNeverClaimedOverUnwrittenFiles(): void
    {
        $this->skipIfRoot('a chmod-0500 corpus directory stays writable for root');
        $dir = $this->scratch('/corpus');
        mkdir($dir, 0o775, true);
        chmod($dir, 0o500);

        $generator = new FuzzCorpusGenerator();
        $plan      = ['notify' => [['value' => '1'], ['value' => '2']]];

        try {
            $generator->generate($dir, $plan);
            $this->fail('a corpus whose files cannot land must never return a manifest');
        } catch (ReportWriteException $failure) {
            $this->assertStringContainsString('writing ' . $dir . '/notify_000.php failed', $failure->getMessage());
            chmod($dir, 0o700);
            // The manifest is a ground-truth claim: nothing may exist for it
            // to lie about.
            $this->assertSame([], glob($dir . '/*.php') ?: []);
        } finally {
            @chmod($dir, 0o700);
        }
    }

    public function testHappyPathFuzzCorpusStillLandsEveryClaimedFile(): void
    {
        $dir = $this->scratch('/corpus-ok');
        $generator = new FuzzCorpusGenerator();
        $plan      = ['notify' => [['value' => '1'], ['value' => '2']]];

        $manifest = $generator->generate($dir, $plan);

        $this->assertCount(2, $manifest);
        foreach ($manifest as $row) {
            $this->assertFileExists($row['file']);
        }
    }
}

/**
 * Minimal visible surface over the trait for pinning: public wrappers for
 * the three protected venue arms.
 */
final class WritesReportFileSink
{
    use WritesReportFile;

    public function store(string $path, string $content): void
    {
        $this->writeFile($path, $content);
    }

    public function addLine(string $path, string $content): void
    {
        $this->appendFile($path, $content);
    }

    public function makeRoom(string $dir): void
    {
        $this->ensureDirectory($dir);
    }
}

/**
 * A user-space stream that accepts half of every buffer — the lying,
 * truncating disk shape PHP's file layer surfaces as a short-write notice
 * and a false return from file_put_contents().
 */
final class ShortSink
{
    /** @var resource|null */
    public $context;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $n = intdiv(strlen($data), 2);
        $this->position += $n;
        return $n;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function stream_seek(int $offset, int $whence)
    {
        $this->position = $offset;
        return true;
    }

    public function stream_close(): void
    {
    }
}
