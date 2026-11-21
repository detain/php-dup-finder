<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\UpdateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class UpdateCommandTest extends TestCase
{
    private ?string $s36Scratch = null;

    protected function tearDown(): void
    {
        if ($this->s36Scratch !== null) {
            foreach (glob($this->s36Scratch . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->s36Scratch);
            $this->s36Scratch = null;
        }
        parent::tearDown();
    }

    public function testValidSignatureIsAccepted(): void
    {
        $command = new TestUpdateCommand(TestUpdateCommand::MODE_VALID_SIGNATURE);

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $outputPath = sys_get_temp_dir() . '/phpdup-valid-test.phar';
        $input = new ArrayInput(['--output' => $outputPath]);
        $output = new NullOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($outputPath);
        unlink($outputPath);
    }

    public function testTamperedPharIsRejected(): void
    {
        $command = new TestUpdateCommand(TestUpdateCommand::MODE_TAMPERED_PHAR);

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $outputPath = sys_get_temp_dir() . '/phpdup-tampered-test.phar';
        $input = new ArrayInput(['--output' => $outputPath]);
        $output = new NullOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($outputPath);
    }

    public function testMissingSignatureIsRejectedWithoutAllowUnsigned(): void
    {
        $command = new TestUpdateCommand(TestUpdateCommand::MODE_NO_SIGNATURE);

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $input = new ArrayInput([]);
        $output = new NullOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(1, $exitCode);
    }

    public function testMissingSignatureIsAcceptedWithAllowUnsigned(): void
    {
        $command = new TestUpdateCommand(TestUpdateCommand::MODE_NO_SIGNATURE);

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $input = new ArrayInput(['--allow-unsigned' => true]);
        $output = new NullOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(0, $exitCode);
    }

    // ------------------------------------------------------------------
    // shell-S36 — honest download completion (php-dup-finder-shell-017)
    // ------------------------------------------------------------------

    public function testReadFailureBeforeEofFailsDownload(): void
    {
        S36FalseReadStream::$nextPayload = str_repeat('a', 600);
        $in = self::openWrapper('s36-false-read://x');
        $dest = $this->scratchFile('torn.bin');
        $out = $this->openOut($dest);
        $io = $this->style($buffer);

        $result = (new TestUpdateCommand(TestUpdateCommand::MODE_S36))->pipe($in, $out, null, $io);
        fclose($in);

        $this->assertFalse($result, 'fread === false before EOF must fail the download');
        $this->assertStringContainsString('read failed before end of stream', $buffer->fetch());
        // Torn partial bytes may linger; the caller unlinks $dest. Success is never claimed.
        $this->assertLessThan(1000, strlen((string)file_get_contents($dest)));
    }

    public function testStalledEmptyReadFailsDownloadWithoutSpinning(): void
    {
        $in = self::openWrapper('s36-stalled-read://x');
        $out = $this->openOut($this->scratchFile('stall.bin'));
        $io = $this->style($buffer);
        $start = microtime(true);

        $result = (new TestUpdateCommand(TestUpdateCommand::MODE_S36))->pipe($in, $out, null, $io);
        fclose($in);

        $this->assertFalse($result, 'a stalled empty read must fail, not loop forever');
        $this->assertLessThan(5.0, microtime(true) - $start, 'stall detection must be immediate');
        $this->assertStringContainsString('read failed before end of stream', $buffer->fetch());
    }

    public function testContentLengthMismatchFailsDownload(): void
    {
        $in = $this->tempStream(str_repeat('b', 500));
        $out = $this->openOut($this->scratchFile('cl.bin'));
        $io = $this->style($buffer);

        $result = (new TestUpdateCommand(TestUpdateCommand::MODE_S36))->pipe($in, $out, 1000, $io);
        fclose($in);

        $this->assertFalse($result);
        $messages = $buffer->fetch();
        $this->assertStringContainsString('Download incomplete', $messages);
        $this->assertStringContainsString('expected 1,000 bytes, received 500', $messages);
    }

    public function testPartialWriteFailsDownload(): void
    {
        // Non-blocking unix socketpair with a shrunken send buffer and no
        // reader: once full, fwrite() accepts fewer bytes than given — the
        // real short-write shape fwrite can exhibit on a stalling sink.
        // Pinned lane choice (shell-S36): refuse, do NOT resume-loop.
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair unavailable');
        }
        [$reader, $writer] = $pair;
        $sock = socket_import_stream($writer);
        if ($sock !== false) {
            @socket_set_option($sock, SOL_SOCKET, SO_SNDBUF, 4096);
        }
        stream_set_blocking($writer, false);
        $filler = str_repeat('x', 65536);
        for ($i = 0; $i < 50; $i++) {
            if (@fwrite($writer, $filler) === 0) {
                break; // send buffer is full
            }
        }

        $in = $this->tempStream($filler);
        $io = $this->style($buffer);

        $result = (new TestUpdateCommand(TestUpdateCommand::MODE_S36))->pipe($in, $writer, null, $io);
        fclose($in);
        fclose($reader);
        // $writer was closed by pipeStream itself.

        $this->assertFalse($result, 'a short fwrite (< chunk length) must fail the download');
        $this->assertStringContainsString('could not write all received bytes', $buffer->fetch());
    }

    public function testHappyPathWritesExactBytesWithProgress(): void
    {
        $payload = str_repeat('c', 131072); // two 64 KiB reads -> a 50 % tick
        $in = $this->tempStream($payload);
        $dest = $this->scratchFile('happy.bin');
        $out = $this->openOut($dest);
        $io = $this->style($buffer);

        $result = (new TestUpdateCommand(TestUpdateCommand::MODE_S36))->pipe($in, $out, 131072, $io);
        fclose($in);

        $this->assertTrue($result);
        $this->assertSame($payload, file_get_contents($dest), 'happy-path bytes unchanged');
        $messages = $buffer->fetch();
        $this->assertStringContainsString('50%', $messages, 'progress reporting kept');
        $this->assertStringContainsString('/ 131,072', $messages);
    }

    public function testTornDownloadRefusesSignatureAndHotSwap(): void
    {
        $command = $this->s36Command(failPharDownload: true);
        $output = new BufferedOutput();

        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Download of phar failed.', $output->fetch());
        $this->assertSame(0, $command->verifySignatureCalls, 'signature check must not run on a torn download');
        $this->assertSame(
            'OLD-PHAR-CONTENT',
            file_get_contents($this->fakePharPath()),
            'the running (fake) phar must never be renamed onto a torn download',
        );
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
    }

    public function testSignatureDownloadFailureAbortsInstall(): void
    {
        $command = $this->s36Command(failSigDownload: true);
        $output = new BufferedOutput();

        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Download of signature file failed.', $output->fetch());
        $this->assertSame(0, $command->verifySignatureCalls);
        $this->assertSame('OLD-PHAR-CONTENT', file_get_contents($this->fakePharPath()));
        $this->assertSame([], $this->scratchGlob('*.tmp.*'), 'downloaded phar must be discarded');
    }

    public function testRealTransportHappyPathHotSwapsViaMockFileServer(): void
    {
        // Mock transport only: file:// URLs backed by scratch files. Never
        // point this at the network or at the checkout's own binaries.
        $command = $this->s36Command();
        $output = new BufferedOutput();

        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $command->verifySignatureCalls);
        $this->assertStringContainsString('verified', $output->fetch());
        $this->assertStringContainsString(
            'NEW-PHAR-BYTES',
            file_get_contents($this->fakePharPath()),
            'hot-swap replaces the phar only after a complete download',
        );
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
        $this->assertSame([], $this->scratchGlob('*.backup.*'));
    }

    // -- helpers ---------------------------------------------------------

    private function scratchDir(): string
    {
        if ($this->s36Scratch === null) {
            $this->s36Scratch = sys_get_temp_dir() . '/phpdup-s36-' . bin2hex(random_bytes(6));
            mkdir($this->s36Scratch, 0700);
        }
        return $this->s36Scratch;
    }

    private function scratchFile(string $name): string
    {
        return $this->scratchDir() . '/' . $name;
    }

    /** @return list<string> */
    private function scratchGlob(string $pattern): array
    {
        return glob($this->scratchDir() . '/' . $pattern) ?: [];
    }

    /** @return resource */
    private function tempStream(string $contents)
    {
        $h = fopen('php://temp', 'r+b');
        $this->assertIsResource($h);
        $this->assertGreaterThan(0, fwrite($h, $contents));
        rewind($h);
        return $h;
    }

    /** @return resource */
    private function openOut(string $path)
    {
        $h = fopen($path, 'wb');
        $this->assertIsResource($h);
        return $h;
    }

    private function style(BufferedOutput &$buffer = null): SymfonyStyle
    {
        $buffer ??= new BufferedOutput();
        return new SymfonyStyle(new ArrayInput([]), $buffer);
    }

    private function fakePharPath(): string
    {
        return $this->scratchFile('phpdup-running.phar');
    }

    private function s36Command(bool $failPharDownload = false, bool $failSigDownload = false): TestUpdateCommand
    {
        $pharSrc = $this->scratchFile('remote.phar');
        $sigSrc = $this->scratchFile('remote.phar.sig');
        file_put_contents($pharSrc, 'NEW-PHAR-BYTES-' . str_repeat('z', 70000));
        file_put_contents($sigSrc, 'deadbeef-deadbeef');
        file_put_contents($this->fakePharPath(), 'OLD-PHAR-CONTENT');

        $command = new TestUpdateCommand(TestUpdateCommand::MODE_S36);
        $command->fakePharPath = $this->fakePharPath();
        $command->pharUrl = 'file://' . $pharSrc;
        $command->sigUrl = 'file://' . $sigSrc;
        $command->failPharDownload = $failPharDownload;
        $command->failSigDownload = $failSigDownload;
        $command->releasePayload = [
            'tag_name'     => 'v9.9.9-s36',
            'prerelease'   => false,
            'draft'        => false,
            'published_at' => '2026-01-01T00:00:00Z',
            'assets'       => [
                ['name' => 'phpdup.phar', 'browser_download_url' => $command->pharUrl],
                ['name' => 'phpdup.phar.sig', 'browser_download_url' => $command->sigUrl],
            ],
        ];

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);
        return $command;
    }

    /** @return resource */
    private static function openWrapper(string $url)
    {
        if (!in_array('s36-false-read', stream_get_wrappers(), true)) {
            stream_wrapper_register('s36-false-read', S36FalseReadStream::class);
        }
        if (!in_array('s36-stalled-read', stream_get_wrappers(), true)) {
            stream_wrapper_register('s36-stalled-read', S36StalledReadStream::class);
        }
        $h = fopen($url, 'rb');
        if ($h === false) {
            throw new \RuntimeException('cannot open test wrapper ' . $url);
        }
        return $h;
    }
}

class TestUpdateCommand extends UpdateCommand
{
    public const MODE_VALID_SIGNATURE = 'valid';
    public const MODE_TAMPERED_PHAR = 'tampered';
    public const MODE_NO_SIGNATURE = 'no-sig';
    public const MODE_S36 = 's36';

    private string $mode;
    private string $testPharContent = '<?php $version = "1.0.0"; echo "test";';

    // shell-S36 controls (only consulted in MODE_S36)
    public ?string $fakePharPath = null;

    /** @var array<string, mixed>|null */
    public ?array $releasePayload = null;
    public string $pharUrl = '';
    public string $sigUrl = '';
    public bool $failPharDownload = false;
    public bool $failSigDownload = false;
    public int $verifySignatureCalls = 0;
    public bool $verifyResult = true;

    public function __construct(string $mode)
    {
        parent::__construct();
        $this->mode = $mode;
    }

    /**
     * Public pass-through onto the download seam (pipeStream) so unit
     * tests can drive stream resources directly.
     *
     * @param resource $in
     * @param resource $out
     */
    public function pipe($in, $out, ?int $expectedBytes, SymfonyStyle $io): bool
    {
        return $this->pipeStream($in, $out, $expectedBytes, $io);
    }

    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        if ($this->mode === self::MODE_S36) {
            // Exercise the REAL UpdateCommand::execute() download caller leg.
            return parent::execute($input, $output);
        }

        $io = new SymfonyStyle($input, $output);
        $outPath = $input->getOption('output');

        if ($this->mode === self::MODE_NO_SIGNATURE) {
            $allowUnsigned = (bool)$input->getOption('allow-unsigned');
            $io->section('Verifying signature…');
            $io->warning('No signature file found — proceeding without verification (--allow-unsigned).');
            if (!$allowUnsigned) {
                $io->error('No signature file found in release. Use --allow-unsigned to install anyway (not recommended).');
                return 1;
            }
            $io->success('Update complete (no signature verification).');
            return 0;
        }

        $tmpFile = $outPath ?? (sys_get_temp_dir() . '/phpdup.phar.tmp.' . bin2hex(random_bytes(8)));

        file_put_contents($tmpFile, $this->testPharContent);
        if ($this->mode === self::MODE_TAMPERED_PHAR) {
            file_put_contents($tmpFile, $this->testPharContent . "\n// TAMPERED");
        }

        $tmpSig = $tmpFile . '.sig';
        $expectedSig = hash_hmac('sha256', $this->testPharContent, $this->getVerificationKey());
        file_put_contents($tmpSig, $expectedSig . "\n");

        $io->section('Verifying signature…');
        if (!$this->verifySignature($tmpFile, trim((string)@file_get_contents($tmpSig)))) {
            @unlink($tmpFile);
            @unlink($tmpSig);
            $io->error("HMAC-SHA256 signature mismatch.\nUse --allow-unsigned to install anyway (not recommended).");
            return 1;
        }

        @unlink($tmpSig);

        if ($outPath !== null) {
            chmod($outPath, 0755 & ~umask());
            $io->success("phpdup written to: {$outPath}");
            return 0;
        }

        $io->success('phpdup updated.');
        return 0;
    }

    private function getVerificationKey(): string
    {
        $rc = new \ReflectionClass(UpdateCommand::class);
        $const = $rc->getConstant('VERIFICATION_KEY');
        return $const !== false ? $const : 'REPLACE_WITH_REAL_PUBLIC_KEY';
    }

    protected function verifySignature(string $pharFile, string $expectedSig): bool
    {
        if ($this->mode === self::MODE_S36) {
            $this->verifySignatureCalls++;
            return $this->verifyResult;
        }
        if ($this->mode === self::MODE_TAMPERED_PHAR) {
            return false;
        }
        if ($this->mode === self::MODE_VALID_SIGNATURE) {
            return true;
        }
        return parent::verifySignature($pharFile, $expectedSig);
    }

    protected function githubToken(): string
    {
        return '';
    }

    protected function runningVersion(): string
    {
        return '0.0.0';
    }

    protected function runningPharPath(): string
    {
        return $this->mode === self::MODE_S36 ? (string)$this->fakePharPath : parent::runningPharPath();
    }

    protected function fetchJson(string $url, array $headers): ?array
    {
        if ($this->mode === self::MODE_S36 && $this->releasePayload !== null) {
            return $this->releasePayload;
        }
        return parent::fetchJson($url, $headers);
    }

    protected function downloadFile(string $url, string $dest, SymfonyStyle $io): bool
    {
        if ($this->mode === self::MODE_S36) {
            if ($this->failPharDownload && $url === $this->pharUrl) {
                return false;
            }
            if ($this->failSigDownload && $url === $this->sigUrl) {
                return false;
            }
        }
        return parent::downloadFile($url, $dest, $io);
    }
}

/**
 * User-space read stream: yields a payload, then stream_read returns false,
 * which PHP surfaces to the consumer as `fread() === false` with feof()
 * still false — the mid-read abort of php-dup-finder-shell-017.
 */
class S36FalseReadStream
{
    public static string $nextPayload = '';

    /** @var mixed */
    public $context;

    private int $pos = 0;
    private string $payload = '';

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->payload = self::$nextPayload;
        $this->pos = 0;
        return true;
    }

    public function stream_read($count)
    {
        if ($this->pos < strlen($this->payload)) {
            $out = substr($this->payload, $this->pos, (int)$count);
            $this->pos += strlen($out);
            return $out;
        }
        return false; // -> fread() === false
    }

    public function stream_eof()
    {
        return false; // never reaches EOF: the false read must be what fails it
    }

    public function stream_stat()
    {
        return false;
    }
}

/**
 * User-space read stream that stalls forever: fread() returns '' while
 * feof() stays false. The old loop spun on this forever; pipeStream must
 * classify it as a read failure.
 */
class S36StalledReadStream
{
    /** @var mixed */
    public $context;

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        return true;
    }

    public function stream_read($count)
    {
        return '';
    }

    public function stream_eof()
    {
        return false;
    }

    public function stream_stat()
    {
        return false;
    }
}
