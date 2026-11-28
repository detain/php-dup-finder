<?php
declare(strict_types=1);

namespace Phpdup\Git;

/**
 * Item 4.2 — shell-free git transport for the history correlation leg.
 *
 * Follows the ScanningStage::runGit precedent (shell-S23): the argv array
 * goes straight to proc_open (execvp — no /bin/sh quoting layer at all),
 * stderr lands in a scratch file so a huge stdout can never deadlock
 * against a full stderr pipe. On top of that transport this class adds the
 * bound item 4.2 needs: every invocation runs against a wall-clock DEADLINE
 * (history is a report-side enrichment; a wedged git — lock contention,
 * gigantic packed history, network-backed alternates — must degrade to the
 * honest 'unmeasurable' posture, never hang or abort the report).
 *
 * The class is deliberately dumb: it reports what git said (stdout,
 * stderr, exit code) and lets callers decide their fail-open shape. The
 * only judgment it encodes is timeout bookkeeping: a killed process
 * returns code 124 (the conventional timeout exit) with whatever stdout
 * arrived before the deadline.
 */
final class GitCli
{
    /** Wall clock a single git command may spend before it is killed. */
    public const TIMEOUT_SECONDS = 15.0;

    /** Exit code this transport reports when it killed the child. */
    public const CODE_TIMEOUT = 124;

    /** Exit code this transport reports when the child could not launch. */
    public const CODE_NO_LAUNCH = 127;

    /**
     * Run one git command by argv (no shell). argv[0] must be 'git'.
     *
     * @param list<string> $argv
     * @return array{0: string, 1: string, 2: int} [stdout, stderr, exit code]
     */
    public static function run(array $argv, float $timeoutSeconds = self::TIMEOUT_SECONDS): array
    {
        $errPath = tempnam(sys_get_temp_dir(), 'phpdup-git-stderr-');
        if ($errPath === false) {
            return ['', 'could not create a scratch file for git stderr', self::CODE_NO_LAUNCH];
        }

        $pipes = [];
        $proc  = proc_open($argv, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $errPath, 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            $stderr = 'could not launch ' . $argv[0];
            @unlink($errPath);
            return ['', $stderr, self::CODE_NO_LAUNCH];
        }
        if (!isset($pipes[1]) || !is_resource($pipes[1])) {
            proc_terminate($proc);
            proc_close($proc);
            @unlink($errPath);
            return ['', 'git stdout pipe unavailable', self::CODE_NO_LAUNCH];
        }

        $stdout   = self::readWithDeadline($pipes[1], $proc, $timeoutSeconds);
        $timedOut = $stdout === null;
        if ($stdout === null) {
            $stdout = '';
        }
        @fclose($pipes[1]);

        if ($timedOut) {
            // The child is already killed inside readWithDeadline; reap it
            // without blocking on a close that would wait for a live proc.
            $status = proc_get_status($proc);
            if ($status['running']) {
                proc_terminate($proc, SIGKILL);
            }
            proc_close($proc);
            $stderr = (string) @file_get_contents($errPath);
            @unlink($errPath);
            return [$stdout, 'git timed out after ' . $timeoutSeconds . 's', self::CODE_TIMEOUT];
        }

        $code   = proc_close($proc);
        $stderr = (string) @file_get_contents($errPath);
        @unlink($errPath);

        return [$stdout, $stderr, $code];
    }

    /**
     * Read stdout to EOF under a deadline, pumping the child's status
     * between chunks. Returns null when the deadline fired (and has
     * SIGKILLed the child); an empty-but-completed read returns ''.
     *
     * @param resource $stdout
     * @param resource $proc
     */
    private static function readWithDeadline($stdout, $proc, float $timeoutSeconds): ?string
    {
        stream_set_blocking($stdout, false);
        $deadline = microtime(true) + $timeoutSeconds;
        $buffer   = '';
        while (true) {
            $chunk = fread($stdout, 65536);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                continue;
            }
            if (feof($stdout)) {
                return $buffer;
            }
            if (microtime(true) >= $deadline) {
                $status = proc_get_status($proc);
                if ($status['running']) {
                    proc_terminate($proc, SIGKILL);
                }
                return null;
            }
            // Brief yield so a slow-but-alive git keeps streaming; the
            // select timeout is the grace, the deadline is the law.
            $read = [$stdout];
            $w    = null;
            $e    = null;
            @stream_select($read, $w, $e, 0, 50_000);
        }
    }

    /**
     * Nearest repository root containing $dir, or null when $dir is not
     * inside a working tree git recognises (no repo, git missing, timeout).
     *
     * The `--show-toplevel` answer is the worktree root as git sees it;
     * callers key every further command through `-C <root>` so relative
     * paths stay in git's own dialect.
     */
    public static function findRepositoryRoot(string $dir): ?string
    {
        [$stdout, , $code] = self::run([
            'git', '-C', $dir, 'rev-parse', '--show-toplevel',
        ]);
        if ($code !== 0) {
            return null;
        }
        $root = trim($stdout);
        return $root !== '' && is_dir($root) ? $root : null;
    }

    /** Current HEAD sha of $repoRoot, or null when history is unreadable. */
    public static function headSha(string $repoRoot): ?string
    {
        [$stdout, , $code] = self::run([
            'git', '-C', $repoRoot, 'rev-parse', 'HEAD',
        ]);
        if ($code !== 0) {
            return null;
        }
        $sha = trim($stdout);
        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }
}
