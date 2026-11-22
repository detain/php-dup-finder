<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Cli\Config;
use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Pipeline\NullProgressListener;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Scanning\FileScanner;
use Phpdup\Util\MemoryDebug;
use Phpdup\Util\PathCanonical;
use Symfony\Component\Console\Output\OutputInterface;

final class ScanningStage implements CooperativeStageInterface
{
    /** Yield to the runtime after every N files so the TUI can repaint. */
    private const YIELD_INTERVAL = 16;

    private readonly ProgressListener $listener;

    public function __construct(?ProgressListener $listener = null)
    {
        $this->listener = $listener ?? new NullProgressListener();
    }

    #[\Override]
    public function name(): Stage
    {
        return Stage::Scanning;
    }

    #[\Override]
    public function run(PipelineState $state, OutputInterface $output): void
    {
        foreach ($this->iter($state, $output) as $_) {
            // synchronous drain
        }
    }

    #[\Override]
    public function iter(PipelineState $state, OutputInterface $output): \Generator
    {
        $config = $state->config;

        // shell-S23 (php-dup-finder-shell-003 + shell-040): the --diff-base
        // git probe now runs FIRST — before any file discovery — through a
        // shell-less argv invocation, per configured root, with stderr kept
        // out of the filename list. A git failure sets $state->scanError
        // and aborts the stage here with $state->files still empty; the CLI
        // driver (Command::dispatch) abandons the pipeline generator when
        // scanError appears, so extraction never starts and the run ends
        // at the exit-2 render (same exit code as before, early timing).
        //
        // argv-safety: the array form of proc_open() hands argv straight to
        // execvp, and `--end-of-options` pins everything after it in rev
        // position — a hostile `--output=/tmp/x` value then arrives at the
        // revision parser as `--output=/tmp/x..HEAD`, which git refuses
        // with "bad revision" (exit 128, nothing written to disk). The old
        // escapeshellarg() framing was worthless: one single-quoted token
        // is still one argv entry, and git parses a leading-`-` token as an
        // option, silently absorbing the appended `..HEAD` into its value.
        $diffBaseFiles = null;
        if ($config->diffBase !== null) {
            $diffBaseFiles = $this->collectDiffBaseFiles($state, $config);
            if ($state->scanError !== null) {
                return;
            }
        }

        // once-S28 (php-dup-finder-once-032): the exclude/exclude_reset of
        // discovered per-directory `.phpdup.json` files are now applied at
        // scan time, not just at knob time. The layers ride into FileScanner
        // as a subtree-scoped overlay (dir-anchored globs, append/reset
        // chain — see the class docblock there), so a nested config's
        // excluded subtree never enters the scan census below, and a
        // resetting subtree re-roots. Configs without either key produce no
        // layer and cannot change the walk.
        $scanner = new FileScanner($config->exclude, perDirExcludes: self::excludeLayers($config));

        // once-S87 (php-dup-finder-once-109 DET-04): `analyze src src`
        // walked the same disk path twice — every block extracted twice,
        // the per-file index layer accumulated duplicate rows, and the
        // refactor stage crashed relocating the second twin. Roots that
        // name one disk location (identical spelling, trailing slash, or
        // two spellings through a symlink) collapse to their first
        // appearance here, before any file is discovered. Distinct roots
        // that merely OVERLAP (a nested `corp corp/Clustering` pair) are
        // not collapsed: per S28 each root binds its own per-directory
        // exclude layer, so the nested root legitimately re-scans under
        // rules its ancestor frame may have pruned — their shared files
        // are deduped by disk identity after the walk instead.
        $rootSpellings = [];
        foreach ($config->paths as $root) {
            $rootSpellings[PathCanonical::diskKey($root)][] = $root;
        }
        $scanRoots = [];
        $collapsedRoots = 0;
        foreach ($rootSpellings as $spellings) {
            $collapsedRoots += count($spellings) - 1;
            // Deterministic representative: the lexicographically-smallest
            // spelling of the disk location, so shuffled argument orders
            // (`src ./src` vs `./src src`) scan identical bytes. Group
            // order follows first appearance; scan order cannot move any
            // output byte anyway — the file list is sorted and file
            // representatives re-chosen post-sort below.
            sort($spellings);
            $scanRoots[] = $spellings[0];
        }

        // Overlap census for the warning line: unordered root pairs where
        // one folded literal is a path-prefix of the other (equal keys
        // cannot survive the collapse above). n is the configured-path
        // count — a handful — so the quadratic scan is free.
        $foldedRoots = [];
        foreach ($scanRoots as $root) {
            $foldedRoots[] = rtrim(PathCanonical::fold($root), '/');
        }
        $overlappingPairs = 0;
        for ($i = 0, $n = count($foldedRoots); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($foldedRoots[$i] === $foldedRoots[$j]) {
                    continue;
                }
                [$shorter, $longer] = strlen($foldedRoots[$i]) < strlen($foldedRoots[$j])
                    ? [$foldedRoots[$i], $foldedRoots[$j]]
                    : [$foldedRoots[$j], $foldedRoots[$i]];
                if (str_starts_with($longer . '/', $shorter . '/')) {
                    $overlappingPairs++;
                }
            }
        }

        $files = [];
        $sinceYield = 0;
        $state->stageStartTime = microtime(true);
        foreach ($scanRoots as $root) {
            foreach ($scanner->scan($root) as $path) {
                $files[] = $path;
                $state->totalFiles++;
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG && $state->totalFiles % 100 === 0) {
                    $msg = sprintf('scanning: discovered file %s [%s]', $path, MemoryDebug::getMemoryUsage());
                    $output->writeln($msg);
                    $state->pushDebugMessage($msg);
                }
                if (++$sinceYield >= self::YIELD_INTERVAL) {
                    $sinceYield = 0;
                    $state->sampleMemory();
                    $state->debug($output, sprintf('scanning: %d files scanned so far [%s]', $state->totalFiles, MemoryDebug::getMemoryUsage()));
                    // Update scannedFiles only at yield intervals so the TUI
                    // sees real progress (not 100 % after every single file).
                    $state->scannedFiles = $state->totalFiles;
                    $this->listener->onFileScanned($state->scannedFiles, $state->totalFiles);
                    yield Stage::Scanning;
                }
            }
        }
        sort($files);

        // once-S87 (php-dup-finder-once-109 DET-04): the walk can still
        // hand the same disk file to the pipeline twice when two
        // DISTINCT roots legitimately overlap (the nested `corp
        // corp/Clustering` shape, kept both scans for their per-directory
        // layers). After sort() the survivors are chosen by disk
        // identity: the lexicographically-smallest literal representative
        // wins, so the surviving spelling never depends on root order —
        // reordered root spellings yield byte-identical file lists,
        // extraction input, and therefore byte-identical downstream
        // reports (the DET-07 determinism property, pinned in
        // AstSerializerTest).
        $seenFileKeys = [];
        $uniqueFiles = [];
        $duplicateFiles = 0;
        foreach ($files as $path) {
            $fileKey = PathCanonical::diskKey($path);
            if (isset($seenFileKeys[$fileKey])) {
                $duplicateFiles++;
                continue;
            }
            $seenFileKeys[$fileKey] = true;
            $uniqueFiles[] = $path;
        }
        $files = $uniqueFiles;

        $state->files = $files;
        $state->totalFiles = count($files);
        $state->scannedFiles = count($files);

        // When --diff-base is set, hand ClusterStage the changed-file list
        // so it can expand the processing scope to the clone cohort (all
        // files sharing n-gram fingerprints with the changed files). The
        // probe itself ran before discovery above; only the reporting of
        // its (already collected) result happens here, in the original
        // position so happy-path output order is unchanged.
        if ($diffBaseFiles !== null) {
            $state->diffBaseFiles = $diffBaseFiles;
            $output->writeln(sprintf(
                "<info>phpdup</info> diff-base %s: %d changed file(s) from git diff --name-only %s..HEAD",
                $config->diffBase,
                count($diffBaseFiles),
                $config->diffBase,
            ));
        }

        // once-S87: the dedupe census, warned ONLY when it actually
        // collapsed something — a clean single-root (or disjoint-roots)
        // run never emits these lines, so ScanningStageTest's output
        // order pins (diff-base line immediately above the summary) hold
        // untouched. Kept below the diff-base report so the summary
        // line stays the stage's final word.
        if ($collapsedRoots > 0) {
            $output->writeln(sprintf(
                "<comment>phpdup</comment> %d duplicate root path(s) collapsed: %d configured → %d distinct disk path(s) scanned",
                $collapsedRoots, count($config->paths), count($scanRoots)
            ));
        }
        if ($overlappingPairs > 0) {
            $output->writeln(sprintf(
                "<comment>phpdup</comment> %d overlapping root pair(s) kept both scans (per-directory exclude layers bind per root); shared files counted once",
                $overlappingPairs
            ));
        }
        if ($duplicateFiles > 0) {
            $output->writeln(sprintf(
                "<comment>phpdup</comment> %d duplicate file path(s) collapsed by disk identity",
                $duplicateFiles
            ));
        }

        // Cover any remaining files that didn't fill a full yield-interval
        // batch (the listener was only called at yield boundaries above).
        $this->listener->onFileScanned($state->scannedFiles, $state->totalFiles);

        $output->writeln(sprintf(
            "<info>phpdup</info> scanning %d path(s) → %d files",
            count($scanRoots), count($files)
        ));
    }

    /**
     * The per-directory exclude overlay ScanningStage feeds FileScanner
     * (once-S28): only discovered configs carrying `exclude` and/or
     * `exclude_reset` become layers; the values arrive already validated
     * (non-empty-string list / boolean) by ConfigLoader. Configs that tune
     * other keys stay out, so the walk's fast path is untouched for them.
     *
     * @return array<string, array{exclude: list<string>, reset: bool}>
     */
    private static function excludeLayers(Config $config): array
    {
        $layers = [];
        foreach ($config->perDirectoryOverrides as $dir => $dict) {
            $hasList = array_key_exists('exclude', $dict);
            $hasReset = array_key_exists('exclude_reset', $dict);
            if (!$hasList && !$hasReset) {
                continue;
            }
            $layers[(string) $dir] = [
                'exclude' => $hasList ? array_values(array_map('strval', (array) $dict['exclude'])) : [],
                'reset' => $hasReset && (bool) $dict['exclude_reset'],
            ];
        }
        return $layers;
    }

    /**
     * Run `git diff --name-only <ref>..HEAD` once per configured root, in
     * that root's own repository (shell-040: no more process-cwd git), and
     * return the resolved changed-file list. On the first failing root the
     * probe is honest: git's real stderr (never merged into the filename
     * list any more) is surfaced via $state->scanError and the stage
     * aborts before discovery.
     *
     * Repo-relative output paths are resolved against the root exactly as
     * before — root-dir == repo-toplevel is the supported shape; subdir
     * and file roots keep the old mapping (once-S34 owns that precision
     * leg). Duplicate realpaths across roots are collapsed.
     *
     * @return list<string>
     */
    private function collectDiffBaseFiles(PipelineState $state, Config $config): array
    {
        $revSpec = $config->diffBase . '..HEAD';

        /** @var array<string, true> $resolved realpath-keyed set */
        $resolved = [];
        foreach ($config->paths as $root) {
            $probeDir = is_dir($root) ? $root : dirname($root);
            [$stdout, $stderr, $code] = self::runGit([
                'git', '-C', $probeDir,
                'diff', '--name-only', '--no-ext-diff',
                '--end-of-options', $revSpec, '--',
            ]);
            if ($code !== 0) {
                $reason = trim($stderr) !== '' ? trim($stderr) : ('git exited with status ' . $code);
                $state->scanError = 'phpdup: --diff-base git diff failed in ' . $probeDir . ': ' . $reason;
                return [];
            }
            foreach (explode("\n", $stdout) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $candidate = $root . '/' . $line;
                if (file_exists($candidate)) {
                    $real = realpath($candidate);
                    if ($real !== false) {
                        $resolved[$real] = true;
                    }
                }
            }
        }

        return array_keys($resolved);
    }

    /**
     * Shell-free git invocation: the array command goes straight to
     * proc_open() (execvp — no /bin/sh word-splitting or quoting layer at
     * all), stdout is captured as one string and stderr lands in a scratch
     * file so a huge stdout can never deadlock against a full stderr pipe.
     *
     * @param list<string> $argv
     * @return array{0: string, 1: string, 2: int} [stdout, stderr, exit code]
     */
    private static function runGit(array $argv): array
    {
        $errPath = tempnam(sys_get_temp_dir(), 'phpdup-git-stderr-');
        if ($errPath === false) {
            return ['', 'could not create a scratch file for git stderr', 127];
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
            return ['', $stderr, 127];
        }
        if (!isset($pipes[1]) || !is_resource($pipes[1])) {
            proc_terminate($proc);
            proc_close($proc);
            @unlink($errPath);
            return ['', 'git stdout pipe unavailable', 127];
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code   = proc_close($proc);
        $stderr = (string) @file_get_contents($errPath);
        @unlink($errPath);

        return [$stdout, $stderr, $code];
    }
}
