<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Server;

use Phpdup\Server\AnalysisWorker;
use PHPUnit\Framework\TestCase;

/**
 * once-S46 (php-dup-finder-once-050): worker death mid-stream ≠ success.
 *
 * The finding's server-side shape: streams that END EARLY (EOF without the
 * sentinel, writes that stop short) were tolerated — a partial frame ending
 * at the peer's EOF was trusted and run (or silently dropped), and a short
 * result write was renamed into place and read as a COMPLETE answer. Both
 * trust boundaries of AnalysisWorker are pinned here:
 *
 *  - read leg: a RUN frame is complete only once its newline sentinel was
 *    consumed; EOF-without-terminator fails loudly with the byte count;
 *    a frame delivered whole then closed stays trusted (and is pinned as
 *    such, so the refusal leg cannot overfire);
 *  - publish leg: the result file appears only after the write status
 *    (returned count AND on-disk size) vouches for every byte; any partial
 *    stream is refused with a "partial" marker and leaves no litter — the
 *    consumer that trusts file-presence (Application reconcile, once-S02)
 *    can never see a truncated file as completion.
 *
 * ApplicationTest keeps its own wire pins (ids, admission, acks); this file
 * is the home of the death-mid-stream discipline for the worker class.
 */
final class AnalysisWorkerTest extends TestCase
{
    /** A mint-shaped job id (16 lowercase hex), per AnalysisWorker::JOB_ID_PATTERN. */
    private const HEX_ID = '0123456789abcdef';

    /** Socket path never bound — handleClient() takes the client stream directly. */
    private const UNUSED_SOCK = '/tmp/phpdup-once-s46-defence-unused.sock';

    /** @var string per-test results dir (0700), mirroring ApplicationTest's wire harness */
    private string $resultDir = '';

    /** @var list<resource> streams to close after each test */
    private array $streams = [];

    protected function setUp(): void
    {
        $this->resultDir = sys_get_temp_dir() . '/phpdup-once-s46-' . uniqid() . '-results';
        mkdir($this->resultDir, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            @fclose($stream);
        }
        $this->streams = [];
        if ($this->resultDir !== '' && is_dir($this->resultDir)) {
            foreach (glob($this->resultDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->resultDir);
        }
    }

    /**
     * @return array{0: resource, 1: resource} worker-facing, test-facing ends
     */
    private function makePair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        stream_set_timeout($pair[1], 5);
        foreach ($pair as $stream) {
            $this->streams[] = $stream;
        }
        return $pair;
    }

    /**
     * Drive one raw byte-buffer through the worker's line handler over a
     * socketpair and return the worker's single reply line ('' when silent).
     *
     * The caller writes BEFORE this runs (so handleClient reads a settled
     * buffer), and may half-close the test end first to simulate a peer
     * that died mid-frame.
     */
    private function drive($client, $controller, string $unusedSock): string
    {
        $handleClient = new \ReflectionMethod(AnalysisWorker::class, 'handleClient');
        try {
            $handleClient->invoke(new AnalysisWorker($unusedSock, $this->resultDir, null), $client);
        } catch (\Throwable $e) {
            $this->fail('handleClient must never throw: ' . $e->getMessage());
        }
        $ack = fgets($controller);
        return $ack === false ? '' : rtrim($ack, "\r\n");
    }

    // ------------------------------------------------------------------
    // Read leg: EOF without the newline sentinel is a partial stream.
    // ------------------------------------------------------------------

    public function testPartialFrameEndingAtPeerEofIsRefusedWithByteMarker(): void
    {
        // The finding's "23 of 40 records then EOF" mapped onto the RUN
        // frame: a truncated stream must fail LOUDLY, naming the bytes it
        // did get, with the shell-022 no-echo discipline (FAIL 0, no raw id).
        [$client, $controller] = $this->makePair();
        $partial = 'RUN ' . self::HEX_ID . ' {"paths": ["a.ph';
        fwrite($controller, $partial);
        // Sender death: the write half closes, no newline was ever sent.
        stream_socket_shutdown($controller, STREAM_SHUT_WR);

        $reply = $this->drive($client, $controller, self::UNUSED_SOCK);

        $this->assertStringStartsWith('FAIL 0 incomplete RUN frame: stream ended after ', $reply);
        $this->assertStringContainsString((string)strlen($partial) . ' bytes', $reply);
        $this->assertStringContainsString('without the newline terminator', $reply);
        // The partial token must never re-enter the frame.
        $this->assertStringNotContainsString(self::HEX_ID, $reply);
        // Refusal is from the sentinel leg, not a coincidental content error.
        $this->assertStringNotContainsString('invalid JSON', $reply);
        $this->assertSame([], glob($this->resultDir . '/*') ?: []);
    }

    public function testFrameCompleteButForItsNewlineIsNeverSilentlyTrusted(): void
    {
        // The historic silent-0 trust: content that decodes and admits fine,
        // missing ONLY the terminating newline because the dispatcher died
        // between the last payload byte and its flush of `\n`. Before
        // once-S46 this exact buffer was acked `OK` and the full pipeline
        // ran on half a frame — the silent-success the finding names. Here
        // it is a frame that WOULD be admitted if read as whole (a refused
        // key would surface as admission FAIL — see the companion pin
        // below); as a sentinel-less stream it must answer the terminator
        // marker instead, acknowledge nothing, run nothing, write nothing.
        [$client, $controller] = $this->makePair();
        $frame = 'RUN ' . self::HEX_ID . ' {"paths":["a.php"],"ml_pair_url":"http://x.invalid/"}';
        fwrite($controller, $frame);
        stream_socket_shutdown($controller, STREAM_SHUT_WR);

        $reply = $this->drive($client, $controller, self::UNUSED_SOCK);

        $this->assertStringStartsWith('FAIL 0 incomplete RUN frame', $reply);
        $this->assertStringContainsString('without the newline terminator', $reply);
        $this->assertStringNotContainsString('OK ' . self::HEX_ID, $reply);
        // Admission never ran: no "must not set" refusal, no accepted job.
        $this->assertStringNotContainsString('must not set', $reply);
        $this->assertSame([], glob($this->resultDir . '/*') ?: []);
    }

    public function testCompleteFrameWithDeliveredSentinelStaysTrustedPastPeerDeath(): void
    {
        // The refusal leg must not overfire: a frame whose newline WAS
        // consumed is complete even when the sender dies right after it
        // (socket_shutdown of the write half — clean EOF). It proceeds to
        // admission, which refuses the refused key with its own named
        // reason — proving the read leg trusted the sentinel, not the EOF.
        [$client, $controller] = $this->makePair();
        $frame = 'RUN ' . self::HEX_ID . ' {"paths":["a.php"],"ml_pair_url":"http://x.invalid/"}' . "\n";
        fwrite($controller, $frame);
        stream_socket_shutdown($controller, STREAM_SHUT_WR);

        $reply = $this->drive($client, $controller, self::UNUSED_SOCK);

        $this->assertStringStartsWith('FAIL ' . self::HEX_ID . ' RUN frame must not set', $reply);
        $this->assertStringContainsString('ml_pair_url', $reply);
        $this->assertStringNotContainsString('incomplete RUN frame', $reply);
    }

    public function testEmptyConnectionStillEndsSilently(): void
    {
        // Probe posture (documented deliberate leg): a connection that
        // carries no bytes names no job — the worker answers nothing and
        // stays alive for the next dispatch (once-050's fix targets
        // PARTIAL streams, not bare connects).
        [$client, $controller] = $this->makePair();
        stream_socket_shutdown($controller, STREAM_SHUT_WR);

        $reply = $this->drive($client, $controller, self::UNUSED_SOCK);

        $this->assertSame('', $reply);
    }

    // ------------------------------------------------------------------
    // Publish leg: write status checked, partial streams never rename.
    // ------------------------------------------------------------------

    /**
     * Invoke the private publish leg over a controlled result string.
     */
    private function publish(AnalysisWorker $worker, string $jobId, string $encoded): ?string
    {
        $publishResult = new \ReflectionMethod(AnalysisWorker::class, 'publishResult');
        /** @var string|null $reason */
        $reason = $publishResult->invoke($worker, $jobId, $encoded);
        return $reason;
    }

    /** A result payload of exactly 40 bytes, for the 23-of-40 marker pins. */
    private function fortyByteResult(): string
    {
        $encoded = '{"clusters":[' . str_repeat('x', 25) . ']}';
        $this->assertSame(40, strlen($encoded)); // self-pin: the literals below assume 40
        return $encoded;
    }
    public function testGreenPublishLandsExactBytesAndNoLitter(): void
    {
        $encoded = $this->fortyByteResult();
        $worker = new AnalysisWorker(self::UNUSED_SOCK, $this->resultDir, null);

        $this->assertNull($this->publish($worker, self::HEX_ID, $encoded));
        $landed = $this->resultDir . '/' . self::HEX_ID . '.json';
        $this->assertFileExists($landed);
        $this->assertSame($encoded, (string)file_get_contents($landed));
        // Exactly one file in the dir: the temp was renamed, nothing left.
        $this->assertSame([$landed], glob($this->resultDir . '/*') ?: []);
    }

    public function testShortWriteIsRefusedWithPartialMarker(): void
    {
        // "23 of 40 records then EOF" verbatim: a writer that streams part
        // of the result and stops without an error must NEVER see the
        // partial temp renamed into the completion slot. The old `=== false`
        // posture accepted this as success.
        $encoded = $this->fortyByteResult();
        $worker = new AnalysisWorker(self::UNUSED_SOCK, $this->resultDir, null);
        $worker->resultWriter = static function (string $tmp, string $bytes): int {
            /** @var int */
            return file_put_contents($tmp, substr($bytes, 0, 23));
        };

        $reason = $this->publish($worker, self::HEX_ID, $encoded);

        $this->assertSame('partial result write: 23 of 40 bytes', $reason);
        $this->assertFileDoesNotExist($this->resultDir . '/' . self::HEX_ID . '.json');
        // No litter: the truncated temp is unlinked on the refusal path.
        $this->assertSame([], glob($this->resultDir . '/*') ?: []);
    }

    public function testLyingFullStatusIsCaughtBySizeCheckEvenOnCleanEof(): void
    {
        // Status is checked even on a CLEAN end: the writer returns the full
        // byte count (no error, "happy EOF") while the on-disk temp holds
        // fewer bytes. The count-only posture would have renamed the short
        // file into completion; the size verification catches the lie.
        $encoded = $this->fortyByteResult();
        $worker = new AnalysisWorker(self::UNUSED_SOCK, $this->resultDir, null);
        $worker->resultWriter = static function (string $tmp, string $bytes): int {
            file_put_contents($tmp, substr($bytes, 0, 30));
            return strlen($bytes);
        };

        $reason = $this->publish($worker, self::HEX_ID, $encoded);

        $this->assertSame('partial result write: landed 30 of 40 bytes', $reason);
        $this->assertFileDoesNotExist($this->resultDir . '/' . self::HEX_ID . '.json');
        $this->assertSame([], glob($this->resultDir . '/*') ?: []);
    }

    public function testFalseStatusKeepsTheLegacyReason(): void
    {
        // The pre-existing consumer-visible string (pinned end-to-end by
        // ApplicationTest's write-failure test) survives the return-shape
        // change unchanged for the honest-error arm.
        $encoded = $this->fortyByteResult();
        $worker = new AnalysisWorker(self::UNUSED_SOCK, $this->resultDir, null);
        $worker->resultWriter = static fn(string $tmp, string $bytes): int|false => false;

        $this->assertSame('failed to write result file', $this->publish($worker, self::HEX_ID, $encoded));
        $this->assertSame([], glob($this->resultDir . '/*') ?: []);
    }

    public function testMidResultWriteSurfacesAsErrFrameWithPartialMarker(): void
    {
        // Full acceptance path, socket-visible: admitted frame gets its
        // accept-ack, then the job runs and the result write dies mid-stream
        // (23 bytes of a larger payload). The consumer that held the channel
        // (once-S02's reader) sees ERR naming the partial byte marker, and
        // no result file exists for the presence-based reconcile to trust.
        // Before this step the same short write RENAMED a partial file into
        // completion and the worker answered NOTHING (silent false truth).
        $root = sys_get_temp_dir() . '/phpdup-once-s46-job-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.php', '<?php echo 1;');
        $cwd = getcwd();
        chdir($root);

        try {
            [$client, $controller] = $this->makePair();
            $worker = new AnalysisWorker(self::UNUSED_SOCK, $this->resultDir, $root);
            $worker->resultWriter = static function (string $tmp, string $bytes): int {
                /** @var int */
                return file_put_contents($tmp, substr($bytes, 0, 23));
            };

            $handleClient = new \ReflectionMethod(AnalysisWorker::class, 'handleClient');
            fwrite($controller, 'RUN ' . self::HEX_ID . ' {"paths":["a.php"]}' . "\n");
            $handleClient->invoke($worker, $client);

            $accept = rtrim((string)fgets($controller), "\r\n");
            $second = rtrim((string)fgets($controller), "\r\n");

            $this->assertSame('OK ' . self::HEX_ID, $accept);
            $this->assertStringStartsWith('ERR ' . self::HEX_ID . ' partial result write: ', $second);
            $this->assertMatchesRegularExpression('/partial result write: \d+ of \d+ bytes/', $second);

            $this->assertFileDoesNotExist($this->resultDir . '/' . self::HEX_ID . '.json');
            $this->assertSame([], glob($this->resultDir . '/*') ?: []);
        } finally {
            chdir($cwd);
            @unlink($root . '/a.php');
            @rmdir($root);
        }
    }
}
