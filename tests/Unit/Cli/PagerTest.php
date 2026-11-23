<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Pager;
use Symfony\Component\Console\Output\BufferedOutput;

final class PagerTest extends TestCase
{
    public function testNeverModeNeverPages(): void
    {
        $this->assertFalse(Pager::shouldPage(Pager::MODE_NEVER));
        $this->assertFalse(Pager::shouldPage(Pager::MODE_NEVER, 99999));
    }

    public function testAlwaysModeAlwaysPages(): void
    {
        $this->assertTrue(Pager::shouldPage(Pager::MODE_ALWAYS));
        $this->assertTrue(Pager::shouldPage(Pager::MODE_ALWAYS, 0));
    }

    public function testAutoIgnoresShortBuffersWhenLineCountKnown(): void
    {
        // The TTY check still gates this in shouldPage() — under PHPUnit
        // STDOUT is typically not a TTY, so the result is false even with
        // a huge buffer. Under PHPUnit, this is the expected non-TTY path.
        $this->assertFalse(Pager::shouldPage(Pager::MODE_AUTO, 1));
    }

    // ==== once-S76 (php-dup-finder-once-092) integrity pins ====

    public function testAutoModeHonorsHermeticTtySeam(): void
    {
        // The third argument is the documented test seam: with it, 'auto'
        // answers the TTY+threshold policy deterministically regardless of
        // whether PHPUnit itself runs on a terminal.
        $this->assertTrue(Pager::shouldPage(Pager::MODE_AUTO, 100, true));
        $this->assertFalse(Pager::shouldPage(Pager::MODE_AUTO, 100, false));
        $this->assertFalse(Pager::shouldPage(Pager::MODE_AUTO, 1, true));
        $this->assertTrue(Pager::shouldPage(Pager::MODE_AUTO, null, true));
        $this->assertFalse(Pager::shouldPage(Pager::MODE_AUTO, null, false));
        $this->assertTrue(Pager::shouldPage(Pager::MODE_AUTO, Pager::THRESHOLD_LINES, true));
        $this->assertFalse(Pager::shouldPage(Pager::MODE_AUTO, Pager::THRESHOLD_LINES - 1, true));
        $this->assertFalse(Pager::shouldPage(Pager::MODE_NEVER, 99999, true));
    }

    public function testAlwaysPolicyStandsAsRequestAndGateLivesInSend(): void
    {
        // Documented policy split (S76): shouldPage(ALWAYS) records the
        // user's request unconditionally — the no-TTY honesty gate is the
        // spawn choke-point send() (see testSendWithoutTerminalNeverSpawns).
        // Flipping this would silently move enforcement away from the seam
        // ReportStage already trusts.
        $this->assertTrue(Pager::shouldPage(Pager::MODE_ALWAYS, 0, false));
        $this->assertTrue(Pager::shouldPage(Pager::MODE_ALWAYS, null, false));
    }

    public function testParseCommandKeepsQuotedArgumentsWhole(): void
    {
        $this->assertSame(
            ['less', '-R'],
            Pager::parseCommand('less -R'),
            'the plain `less -R` shape splits to exactly two argv entries',
        );
        $this->assertSame(
            ['/my less', '-R', 'a b', 'cd ef', '--p=unclosed'],
            Pager::parseCommand("'/my less' -R \"a b\" c\"d e\"f --p=\"unclosed"),
            'quoted sections survive as single argv entries, bare+quoted mix concatenates into ONE '
            . 'token (shell-style), and an unterminated quote keeps its whole remainder (lossless splitter)',
        );
        $this->assertSame([''], Pager::parseCommand('""'), 'an empty quoted section is a token, not noise');
        $this->assertSame([], Pager::parseCommand('   '), 'whitespace-only carries no argv');
    }

    public function testParseCommandBackslashEscapesOutsideQuotes(): void
    {
        $this->assertSame(
            ['less -R', '-F'],
            Pager::parseCommand('less\\ -R -F'),
            'an escaped space outside quotes keeps one token (shell-style)',
        );
    }

    public function testResolveAppendsRawFlagOnceForLessOnly(): void
    {
        $this->assertSame(['less', '-R'], Pager::resolvePagerCommand('less'), 'bare less gains -R');
        $this->assertSame(['less', '-R'], Pager::resolvePagerCommand('less -R'), '-R is never duplicated');
        $this->assertSame(['less', '-RF'], Pager::resolvePagerCommand('less -RF'), 'cluster already carries R');
        $this->assertSame(['less', '-FsR'], Pager::resolvePagerCommand('less -FsR'), 'R inside a cluster counts');
        $this->assertSame(
            ['less', '--status-column', '-R'],
            Pager::resolvePagerCommand('less --status-column'),
            'long options are NOT word-boundary hits for -R (the str_contains class)',
        );
        $this->assertSame(
            ['less', '-r', '-R'],
            Pager::resolvePagerCommand('less -r'),
            'lowercase -r (strip-control, different contract) does not count as -R',
        );
    }

    public function testResolveNeverTouchesNonLessPagers(): void
    {
        // once-092 worded it: a "vim -R" would false-positive. vim's -R is
        // READ-ONLY mode, not less's raw pass-through — the binary name
        // must gate the whole concept.
        $this->assertSame(['vim', '-R'], Pager::resolvePagerCommand('vim -R'));
        $this->assertSame(
            ['lessish', '--status-column'],
            Pager::resolvePagerCommand('lessish --status-column'),
            'str_starts_with(trim($pager),"less") used to page into "lessish" — basename truth says no',
        );
        $this->assertSame(['more'], Pager::resolvePagerCommand('more'));
    }

    public function testResolveAppendsForAbsolutePathLess(): void
    {
        $this->assertSame(
            ['/usr/local/bin/less', '-F', '-R'],
            Pager::resolvePagerCommand('/usr/local/bin/less -F'),
            'the old prefix test missed an absolute less path — basename does not',
        );
    }

    public function testDefaultCommandResolvesToLessWithRawFlag(): void
    {
        $this->assertSame(['less', '-R'], Pager::resolvePagerCommand(Pager::DEFAULT_COMMAND));
    }

    public function testSendWithoutTerminalNeverSpawns(): void
    {
        // once-092 leg 2: MODE_ALWAYS (the only caller route here) must not
        // proc_open a pager onto a pipe/file — the tripwire below proves
        // zero spawn, and the buffer proves the bytes arrive direct.
        $dir = $this->scratchDir();
        $marker = $dir . '/spawned';
        $cmd = $this->pagerCmd('file_put_contents("' . $marker . '", "x");');
        $this->withPager($cmd, function () use ($marker): void {
            $out = new BufferedOutput();
            Pager::send("REPORT-BODY\n", $out, false);
            $this->assertSame(
                "phpdup: pager requested but stdout is not a terminal — wrote the report unpaged.\nREPORT-BODY\n",
                $out->fetch(),
            );
        });
        $this->assertFileDoesNotExist($marker, 'no-TTY send() must never reach proc_open');
        $this->cleanupDir($dir);
    }

    public function testSendEmptyPagerDisclosesInsteadOfSubstitutingDefault(): void
    {
        // Honest args: an explicitly empty $PAGER is a statement, not an
        // unset variable — silently running `less -R` instead was a lie.
        $out = new BufferedOutput();
        $previous = getenv('PAGER');
        putenv('PAGER=');
        try {
            Pager::send("BODY\n", $out, true);
        } finally {
            if ($previous === false) {
                putenv('PAGER');
            } else {
                putenv('PAGER=' . $previous);
            }
        }
        $this->assertSame(
            "phpdup: \$PAGER is set but empty — wrote the report unpaged.\nBODY\n",
            $out->fetch(),
        );
    }

    public function testSendDeliversEveryByteThroughShortWriteLoop(): void
    {
        // once-092 leg 1 acceptance in unit form: >64 KiB (one Linux pipe
        // buffer) paged output arrives complete — the short-write loop, not
        // a single @fwrite, moves the bytes. Marker file == exact payload.
        $dir = $this->scratchDir();
        $marker = $dir . '/bytes';
        $payload = str_repeat('phpdup line\n', 18619); // 223,428 bytes
        $this->withPager($this->pagerCmd('file_put_contents("' . $marker . '", stream_get_contents(STDIN));'), function () use ($marker, $payload): void {
            $out = new BufferedOutput();
            Pager::send($payload, $out, true);
            $this->assertSame('', $out->fetch(), 'full delivery pages silently — no disclosure, no unpaged tail');
        });
        $this->assertSame($payload, (string)@file_get_contents($marker), 'bytes == source');
        $this->cleanupDir($dir);
    }

    public function testSendDisclosesTruncationAndCompletesBytesForEarlyExitingPager(): void
    {
        // The defect shape: a pager that stops reading (head-style, or a
        // crashed less) used to silently truncate reports >64 KiB.
        // After the cure the event is disclosed with exact counts and the
        // undelivered tail is written unpaged. The pager exits 0 on purpose:
        // even a *successful-looking* early exit is caught by the write
        // accounting, which is the whole point.
        $payload = str_repeat('y', 204800);
        $this->withPager($this->pagerCmd('exit(0);'), function () use ($payload): void {
            $out = new BufferedOutput();
            Pager::send($payload, $out, true);
            $text = $out->fetch();
            $nl = strpos($text, "\n");
            $this->assertIsInt($nl, 'a truncation disclosure line must exist');
            $disclosure = substr($text, 0, $nl);
            $this->assertSame(1, preg_match(
                '/^phpdup: pager ".+" stopped after (\d+) of (\d+) bytes \(exit (-?\d+)\) — wrote the remaining (\d+) bytes unpaged\.$/',
                $disclosure,
                $m,
            ));
            $written = (int)$m[1];
            $total = (int)$m[2];
            $remaining = (int)$m[4];
            $this->assertSame(strlen($payload), $total);
            $this->assertLessThan($total, $written, 'the pager genuinely cut the stream (premise)');
            $this->assertSame($total - $written, $remaining);
            $this->assertSame(
                substr($payload, $written),
                substr($text, $nl + 1),
                'the remaining bytes still reach the consumer — truncation is disclosed, never silent',
            );
        });
    }

    public function testSendDisclosesNonZeroStatusAfterFullDelivery(): void
    {
        $cmd = $this->pagerCmd('stream_get_contents(STDIN); exit(7);');
        $this->withPager($cmd, function () use ($cmd): void {
            $out = new BufferedOutput();
            Pager::send("ALL-BYTES-IN\n", $out, true);
            $this->assertSame(
                'phpdup: pager "' . $cmd . '" exited with status 7 after receiving the full report.' . "\n",
                $out->fetch(),
            );
        });
    }

    public function testSendDisclosesAndWritesUnpagedWhenPagerCannotLaunch(): void
    {
        $this->withPager('/no/such/pager-xyz/less', function (): void {
            $out = new BufferedOutput();
            Pager::send("ABCDEF\n", $out, true);
            $this->assertSame(
                "phpdup: cannot launch pager \"/no/such/pager-xyz/less\" — wrote the report unpaged.\nABCDEF\n",
                $out->fetch(),
            );
        });
    }

    /** Shell-style $PAGER value running $code; $code must use only double quotes. */
    private function pagerCmd(string $code): string
    {
        return PHP_BINARY . " -r '" . $code . "'";
    }

    /** @param callable(): void $run */
    private function withPager(string $command, callable $run): void
    {
        $previous = getenv('PAGER');
        putenv('PAGER=' . $command);
        try {
            $run();
        } finally {
            if ($previous === false) {
                putenv('PAGER');
            } else {
                putenv('PAGER=' . $previous);
            }
        }
    }

    private function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-s76-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            $this->fail('cannot create scratch dir ' . $dir);
        }
        return $dir;
    }

    private function cleanupDir(string $dir): void
    {
        foreach ((array)glob($dir . '/*') as $file) {
            @unlink((string)$file);
        }
        @rmdir($dir);
    }
}
