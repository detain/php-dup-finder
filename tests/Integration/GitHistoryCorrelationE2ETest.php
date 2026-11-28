<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use Phpdup\Reporting\JsonSchemaSpec;
use PHPUnit\Framework\TestCase;

/**
 * Item 4.2 end-to-end: git-history correlation through the REAL
 * `bin/phpdup` surface over a live test repository.
 *
 * The fixture mirrors the doc's taxonomy (improve_logic.md 4.2):
 *  - a/b  : arithmetic twin pair, base 900d old, twin b fixed ALONE 60d
 *    ago by a "Fix ..." commit -> recent_lone_fix + history-lone-fix
 *    finding (the exact "fixed in March / untouched for two years" shape
 *    behind the 14 phlix bugs);
 *  - twin2/twin_new : identical strpair twins seeded 200d ago under the
 *    names twin2.php + twin_old.php, then twin_old was `git mv`-renamed
 *    14d ago -> the co-change leg must keep counting the seed commit for
 *    the renamed twin (blame `filename` alias) -> in_sync with one
 *    co-change credit;
 *  - c/d  : both added TOGETHER in one 7d-old commit -> in_sync, co=1;
 *  - g/h  : twins untouched for 900d -> long_drifted, no activity;
 *  - u1/u2: byte-identical pair NEVER COMMITTED -> unmeasurable with the
 *    untracked bucket note, exit code unchanged (fail-open law).
 *
 * Harness laws inherited from GitIgnoreHonoredTest (stdin closed, fresh
 * throwaway cache_dir per phase, wall-clock deadline + SIGKILL).
 *
 * The repo fixture is committed with GIT_*_DATE env stamps in
 * chronological commit order on purpose: git log --since stops walking a
 * branch at the first below-window commit, so a deliberately scrambled
 * fixture would exercise a git traversal artifact instead of the
 * analyzer (analyzer docblock notes the same posture).
 */
final class GitHistoryCorrelationE2ETest extends TestCase
{
    private const TIMEOUT_S = 120.0;

    private string $tmp;
    private string $repo;
    private string $plain;
    private int $base;

    protected function setUp(): void
    {
        if (self::gitAvailable() === false) {
            self::markTestSkipped('git binary is required for the item 4.2 e2e fixture');
        }
        $this->tmp   = sys_get_temp_dir() . '/phpdup_hist_' . uniqid('t', true);
        $this->repo  = $this->tmp . '/repo';
        $this->plain = $this->tmp . '/plain';
        mkdir($this->repo, 0o775, true);
        mkdir($this->plain, 0o775, true);
        $this->base  = time();
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    // ------------------------------------------------------------------ scenarios

    public function testArmedRunDeliversTheDocVerdicts(): void
    {
        $run   = $this->cli(['analyze', $this->repo, '--json', $this->tmp . '/armed.json'], cfgExtra: ['history' => true], cacheTag: 'armed');
        self::assertSame(0, $run['exit'], 'armed run must never fatal: ' . $run['combined']);

        $report = $this->reportOf($this->tmp . '/armed.json');
        $block  = $report['summary']['cluster_history'] ?? null;
        self::assertIsArray($block, 'armed payload must carry the conditional summary key');
        self::assertSame(12, $block['window_months']);
        self::assertSame(0, $block['omitted']);
        self::assertSame(5, $block['clusters']);

        $rows = $this->rowsByFiles($block['rows']);

        // (1) the fix-one-twin pattern: exact doc verdict + lone proof.
        $fix = $rows['a.php+b.php'] ?? null;
        self::assertNotNull($fix);
        self::assertSame('recent_lone_fix', $fix['verdict']);
        $byFile = [];
        foreach ($fix['members'] as $m) {
            $byFile[$m['file']] = $m;
        }
        self::assertNull($byFile['a.php']['lone']);
        self::assertSame($this->day(900), $byFile['a.php']['last_date'], 'the untouched twin still answers with its 900-day-old commit');
        self::assertNotNull($byFile['b.php']['lone'], 'the fix commit must surface as a lone change');
        self::assertSame($this->day(60), $byFile['b.php']['lone']['date']);
        self::assertSame('Fix off-by-one in total threshold', $byFile['b.php']['lone']['subject']);
        self::assertTrue($byFile['b.php']['lone']['fix']);

        // (2) rename-aware co-change: the seed commit keeps counting for
        //     the twin that now lives under a renamed path.
        $rn = $rows['twin2.php+twin_new.php'] ?? null;
        self::assertNotNull($rn);
        self::assertSame('in_sync', $rn['verdict']);
        self::assertNotSame([], $rn['co_change'], 'rename aliases must preserve the seed co-change credit');
        self::assertSame(['twin2.php', 'twin_new.php'], [$rn['co_change'][0]['a'], $rn['co_change'][0]['b']]);
        self::assertSame(1, $rn['co_change'][0]['times']);
        foreach ($rn['members'] as $m) {
            self::assertSame($this->day(200), $m['last_date'], 'blame crossed the rename to the seed commit');
        }

        // (3) co-added twins are in_sync with their shared commit counted.
        $cc = $rows['c.php+d.php'] ?? null;
        self::assertNotNull($cc);
        self::assertSame('in_sync', $cc['verdict']);
        self::assertSame(1, $cc['co_change'][0]['times'] ?? -1);

        // (4) long drift: both silent for 900 days.
        $ld = $rows['g.php+h.php'] ?? null;
        self::assertNotNull($ld);
        self::assertSame('long_drifted', $ld['verdict']);
        self::assertNull($ld['reason']);
        self::assertSame([], $ld['co_change']);
        self::assertSame($this->day(900), $ld['members'][0]['last_date']);

        // (5) untracked pair: honest unmeasurable + skip-with-note, no fatal.
        $un = $rows['#untracked_files'] ?? null;
        self::assertNotNull($un, 'the never-committed pair must report unmeasurable/untracked_files');
        self::assertSame([], $un['members']);
        self::assertSame([], $un['co_change']);
        $historyNotes = array_values(array_filter($report['summary']['scan_notes'], static fn($n) => str_starts_with($n, 'history:')));
        self::assertNotCount(0, $historyNotes);
        self::assertStringContainsString('untracked members', $historyNotes[0]);
        self::assertStringContainsString('unmeasurable', $historyNotes[0]);
    }

    public function testLoneFixFindingRidesTheCluster(): void
    {
        $run = $this->cli(['analyze', $this->repo, '--json', $this->tmp . '/find.json'], cfgExtra: ['history' => true], cacheTag: 'find');
        self::assertSame(0, $run['exit']);

        $report = $this->reportOf($this->tmp . '/find.json');
        $rows   = $this->rowsByFiles($report['summary']['cluster_history']['rows']);
        $fixId  = $rows['a.php+b.php']['cluster'];

        $found = null;
        foreach ($report['clusters'] as $c) {
            if ($c['id'] === $fixId) {
                $found = $c;
            }
        }
        self::assertNotNull($found, 'the correlated cluster must exist in the payload');
        $codes = array_column($found['architectural_findings'], 'code');
        self::assertContains('history-lone-fix', $codes);
        foreach ($found['architectural_findings'] as $f) {
            if ($f['code'] === 'history-lone-fix') {
                self::assertSame('warning', $f['severity']);
                self::assertSame('ClusterHistoryAnalyzer', $f['analyzer']);
                self::assertStringContainsString('Fix off-by-one in total threshold', $f['message']);
                self::assertStringContainsString('b.php', $f['message']);
            }
        }
    }

    public function testUnarmedPostureStaysInert(): void
    {
        $run = $this->cli(['analyze', $this->repo, '--json', $this->tmp . '/off.json'], cacheTag: 'off');
        self::assertSame(0, $run['exit']);

        $raw    = (string) file_get_contents($this->tmp . '/off.json');
        $report = $this->reportOf($this->tmp . '/off.json');
        self::assertFalse(strpos($raw, 'cluster_history'), 'no cluster_history string anywhere in an unarmed report');
        self::assertFalse(strpos($raw, 'history-lone-fix'), 'no history findings when unarmed');
        self::assertArrayNotHasKey('cluster_history', $report['summary']);
        self::assertSame(
            array_keys(JsonSchemaSpec::summaryShape(false)),
            array_keys($report['summary']),
            'the unarmed summary key set stays pinned to the unarmed spec — the golden posture',
        );

        // Everything but the armed additions must be identical to the
        // armed run: history correlation never disturbs detection itself.
        $onRun = $this->cli(['analyze', $this->repo, '--json', $this->tmp . '/on-cmp.json'], cfgExtra: ['history' => true], cacheTag: 'cmp');
        self::assertSame(0, $onRun['exit']);
        $on   = $this->reportOf($this->tmp . '/on-cmp.json');
        unset($on['summary']['cluster_history']);
        $stripNotes  = static fn(array $notes): array => array_values(array_filter($notes, static fn($n) => !str_starts_with($n, 'history:')));
        $on['summary']['scan_notes']  = $stripNotes($on['summary']['scan_notes']);
        $report['summary']['scan_notes'] = $stripNotes($report['summary']['scan_notes']);
        $stripFindings = static fn(array $clusters): array => array_map(
            static function (array $c): array {
                $c['architectural_findings'] = array_values(array_filter(
                    $c['architectural_findings'],
                    static fn($f) => !str_starts_with($f['code'], 'history-'),
                ));
                return $c;
            },
            $clusters,
        );
        $on['clusters']     = $stripFindings($on['clusters']);
        $report['clusters'] = $stripFindings($report['clusters']);
        self::assertSame($report, $on, 'unarmed vs armed: identical modulo the deliberate history additions');
        foreach ($report['summary']['scan_notes'] as $note) {
            self::assertStringNotContainsString('history:', $note);
        }
        foreach ($report['clusters'] as $c) {
            foreach ($c['architectural_findings'] as $f) {
                self::assertStringNotContainsString('history-', $f['code']);
            }
        }
    }

    public function testCliFlagRouteMatchesTheConfigKey(): void
    {
        $viaFlag = $this->cli(['analyze', $this->repo, '--json', $this->tmp . '/flag.json', '--history'], cacheTag: 'flag');
        $viaKey  = $this->cli(['analyze', $this->repo, '--json', $this->tmp . '/key.json'], cfgExtra: ['history' => true], cacheTag: 'key');
        self::assertSame(0, $viaFlag['exit'], $viaFlag['combined']);
        self::assertSame(0, $viaKey['exit']);

        $flag = $this->reportOf($this->tmp . '/flag.json');
        $key  = $this->reportOf($this->tmp . '/key.json');
        self::assertSame($key['summary']['cluster_history'], $flag['summary']['cluster_history']);
        self::assertSame($key['summary']['scan_notes'], $flag['summary']['scan_notes']);
    }

    public function testWindowKnobRewindsTheVerdict(): void
    {
        $run = $this->cli(['analyze', $this->repo, '--json', $this->tmp . '/w1.json'], cfgExtra: ['history' => true, 'history_window_months' => 1], cacheTag: 'w1');
        self::assertSame(0, $run['exit']);

        $block = $this->reportOf($this->tmp . '/w1.json')['summary']['cluster_history'];
        self::assertSame(1, $block['window_months']);
        $rows = $this->rowsByFiles($block['rows']);
        self::assertSame('long_drifted', $rows['a.php+b.php']['verdict'], 'a 60-day-old lone fix falls outside a one-month window');
        self::assertSame('in_sync', $rows['c.php+d.php']['verdict'], 'a seven-day-old shared commit stays inside any sane window');
        self::assertSame([], $rows['twin2.php+twin_new.php']['co_change'], 'the 200-day seed commit falls out of the short window');
    }

    public function testNonRepositoryTargetFailsOpenToUnmeasurable(): void
    {
        $run = $this->cli(['analyze', $this->plain, '--json', $this->tmp . '/plain.json'], cfgExtra: ['history' => true], cacheTag: 'plain');
        self::assertSame(0, $run['exit'], 'no repository must never be fatal — skip-with-note posture');

        $report = $this->reportOf($this->tmp . '/plain.json');
        $block  = $report['summary']['cluster_history'];
        self::assertCount(1, $block['rows']);
        self::assertSame('unmeasurable', $block['rows'][0]['verdict']);
        self::assertSame('no_repository', $block['rows'][0]['reason']);
        $historyNotes = array_values(array_filter($report['summary']['scan_notes'], static fn($n) => str_starts_with($n, 'history:')));
        self::assertNotCount(0, $historyNotes);
        self::assertStringContainsString('outside any git repository', $historyNotes[0]);
    }

    public function testCacheWarmRunIsDeterministic(): void
    {
        $cold = $this->cli(['analyze', $this->repo, '--json', $this->tmp . '/cold.json'], cfgExtra: ['history' => true], cacheTag: 'warm');
        self::assertSame(0, $cold['exit']);
        $historyDir = $this->tmp . '/cache-warm/history';
        self::assertDirectoryExists($historyDir, 'the blame/log cache subtree must materialize under cache_dir');
        self::assertDirectoryExists($historyDir . '/blame');
        self::assertDirectoryExists($historyDir . '/log');

        $warm = $this->cli(['analyze', $this->repo, '--json', $this->tmp . '/warm.json'], cfgExtra: ['history' => true], cacheTag: 'warm');
        self::assertSame(0, $warm['exit']);
        self::assertSame(
            $this->reportOf($this->tmp . '/cold.json')['summary']['cluster_history'],
            $this->reportOf($this->tmp . '/warm.json')['summary']['cluster_history'],
            'a cached second answer must be identical, never a stale drift',
        );
    }

    // ------------------------------------------------------------------ fixture

    private function buildFixture(): void
    {
        $bodies = [
            'arith' => $this->arithBody(),
            'str'   => $this->strpairBody(),
            'long'  => $this->longpairBody(),
            'slug'  => $this->cochangeBody(),
            'track' => $this->trackpairBody(),
        ];

        $this->git(['-c', 'init.defaultBranch=main', 'init', '-q', $this->repo]);
        $this->git(['config', 'user.name', 'H']);
        $this->git(['config', 'user.email', 'h@t']);
        $this->git(['config', 'commit.gpgsign', 'false']);

        // 1) import (-900d): a/b twin + g/h twin
        file_put_contents("{$this->repo}/a.php", $bodies['arith']);
        file_put_contents("{$this->repo}/b.php", $bodies['arith']);
        file_put_contents("{$this->repo}/g.php", $bodies['long']);
        file_put_contents("{$this->repo}/h.php", $bodies['long']);
        $this->git(['add', '-A'], daysAgo: 900);
        $this->git(['commit', '-q', '-m', 'Import helpers and long tree'], daysAgo: 900);

        // 2) seed (-200d): twin_old/twin2
        file_put_contents("{$this->repo}/twin_old.php", $bodies['str']);
        file_put_contents("{$this->repo}/twin2.php", $bodies['str']);
        $this->git(['add', '-A'], daysAgo: 200);
        $this->git(['commit', '-q', '-m', 'Seed twin pair'], daysAgo: 200);

        // 3) lone fix (-60d): b only, one literal drifts (still a clone)
        file_put_contents("{$this->repo}/b.php", str_replace('$total > 100', '$total > 150', $bodies['arith']));
        $this->git(['add', '-A'], daysAgo: 60);
        $this->git(['commit', '-q', '-m', 'Fix off-by-one in total threshold'], daysAgo: 60);

        // 4) rename (-14d): twin_old -> twin_new (pure move)
        $this->git(['mv', 'twin_old.php', 'twin_new.php'], daysAgo: 14);
        $this->git(['commit', '-q', '-m', 'Rename legacy twin file'], daysAgo: 14);

        // 5) co-add (-7d): c/d together
        file_put_contents("{$this->repo}/c.php", $bodies['slug']);
        file_put_contents("{$this->repo}/d.php", $bodies['slug']);
        $this->git(['add', '-A'], daysAgo: 7);
        $this->git(['commit', '-q', '-m', 'Add shared formatter twins'], daysAgo: 7);

        // untracked in-repo pair + non-repo plain pair (distinct bodies)
        file_put_contents("{$this->repo}/u1.php", $bodies['track']);
        file_put_contents("{$this->repo}/u2.php", $bodies['track']);
        file_put_contents("{$this->plain}/p1.php", $bodies['track']);
        file_put_contents("{$this->plain}/p2.php", $bodies['track']);
    }

    private function arithBody(): string
    {
        return <<<'PHP'
            <?php
            function compute_total($items, $tax_rate) {
                $count = 0;
                $subtotal = 0.0;
                foreach ($items as $item) {
                    $count = $count + 1;
                    $price = $item['price'];
                    $qty = $item['qty'];
                    $line = $price * $qty;
                    $subtotal = $subtotal + $line;
                    if ($line > 50) {
                        $subtotal = $subtotal - ($line * 0.05);
                    }
                }
                $tax = $subtotal * $tax_rate;
                $total = $subtotal + $tax;
                if ($total > 100) {
                    $total = $total - 5;
                }
                if ($count > 10) {
                    $total = $total * 0.98;
                }
                return round($total, 2);
            }
            PHP . "\n";
    }

    private function strpairBody(): string
    {
        return <<<'PHP'
            <?php
            function format_label($parts, $sep, $seed) {
                $head = strtoupper($parts[0]);
                $tail = strtolower($parts[1]);
                $mid = trim($parts[2]);
                $joined = $head . $sep . $mid . $sep . $tail;
                $padded = str_pad($joined, 24, '-');
                if (strlen($padded) > 30) {
                    $padded = substr($padded, 0, 30);
                }
                $flip = strrev($padded);
                if (strpos($flip, 'a') !== false) {
                    $flip = ucfirst($flip);
                }
                $fold = wordwrap($flip, 12, '|', true);
                if (substr_count($fold, '|') > 2) {
                    $fold = rtrim($fold, '|');
                }
                $wrap = str_replace('|', ' ', $fold);
                if (strpos($wrap, '  ') !== false) {
                    $wrap = preg_replace('/\s+/', ' ', $wrap);
                }
                $boxed = '|' . trim($wrap) . '|';
                if (strlen($boxed) > 48) {
                    $boxed = substr($boxed, 0, 48);
                }
                return $boxed . $seed;
            }
            PHP . "\n";
    }

    private function longpairBody(): string
    {
        return <<<'PHP'
            <?php
            function flatten_tree($node, $depth) {
                $out = [];
                $stack = [$node];
                while ($stack !== []) {
                    $cur = array_pop($stack);
                    if (is_array($cur)) {
                        foreach ($cur as $child) {
                            $stack[] = $child;
                        }
                        if ($depth > 4) {
                            $depth = $depth - 1;
                        }
                    } else {
                        $out[] = $cur;
                    }
                }
                $merged = array_values(array_filter($out, static fn($v) => $v !== null));
                if (count($merged) > 64) {
                    $merged = array_slice($merged, 0, 64);
                }
                return $merged;
            }
            PHP . "\n";
    }

    private function cochangeBody(): string
    {
        return <<<'PHP'
            <?php
            function slug_title($title, $seed) {
                $clean = preg_replace('/[^a-z0-9 ]/i', '', $title);
                $lower = strtolower($clean);
                $squash = preg_replace('/\s+/', '-', $lower);
                if (strlen($squash) > 40) {
                    $squash = substr($squash, 0, 40);
                }
                $trimmed = rtrim($squash, '-');
                $parts = explode('-', $trimmed);
                if (count($parts) > 3) {
                    $parts = array_slice($parts, 0, 3);
                }
                $rebuilt = implode('-', $parts);
                $hashed = md5($rebuilt . $seed);
                if (strlen($hashed) < 8) {
                    $hashed = str_pad($hashed, 8, '0');
                }
                $checked = htmlspecialchars($rebuilt, ENT_QUOTES);
                if ($checked === '' && $seed > 0) {
                    $checked = 'untitled-' . $seed;
                }
                return $hashed . ':' . $checked;
            }
            PHP . "\n";
    }

    private function trackpairBody(): string
    {
        return <<<'PHP'
            <?php
            function tally_window($events, $floor) {
                $sums = [];
                foreach ($events as $hour => $delta) {
                    if (!isset($sums[$hour])) {
                        $sums[$hour] = 0;
                    }
                    $sums[$hour] = $sums[$hour] + $delta;
                    if ($sums[$hour] < $floor) {
                        $sums[$hour] = $floor;
                    }
                }
                $peak = 0;
                foreach ($sums as $bucket) {
                    if ($bucket > $peak) {
                        $peak = $bucket;
                    }
                }
                $total = 0;
                foreach ($sums as $bucket) {
                    $total = $total + $bucket;
                }
                return [$sums, $peak, $total];
            }
            PHP . "\n";
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<string> $argv */
    private function git(array $argv, ?int $daysAgo = null): string
    {
        $env = getenv();
        if ($daysAgo !== null) {
            $stamp = '@' . ($this->base - $daysAgo * 86400);
            $env['GIT_AUTHOR_DATE']     = $stamp;
            $env['GIT_COMMITTER_DATE']  = $stamp;
        }
        // Belt law: every git call is anchored with -C to the fixture repo
        // — the process cwd is NEVER load-bearing (a cwd-only variant of
        // this helper once committed the lane's working tree under the
        // fixture's message; -C makes the target unmissable).
        $proc = proc_open(
            array_merge(['git', '-C', $this->repo], $argv),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmp,
            $env,
        );
        self::assertIsResource($proc, 'git child refused: ' . implode(' ', $argv));
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertSame(0, $code, 'git ' . implode(' ', $argv) . " => $code: $err");
        return trim($out);
    }

    private static function gitAvailable(): bool
    {
        $proc = @proc_open(['git', '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p);
        if (!is_resource($proc)) {
            return false;
        }
        // Drain BEFORE close: a pipe closed under a still-writing child
        // earns SIGPIPE and a misleading exit code.
        stream_get_contents($p[1]);
        stream_get_contents($p[2]);
        fclose($p[1]);
        fclose($p[2]);
        return proc_close($proc) === 0;
    }

    private function day(int $daysAgo): string
    {
        return gmdate('Y-m-d', $this->base - $daysAgo * 86400);
    }

    /** @return array<string,mixed> */
    private function reportOf(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /**
     * Index rows by their sorted member file names, or '#<reason>' for the
     * unmeasurable rows that answer with no member detail.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function rowsByFiles(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $files = array_map(static fn($m) => $m['file'], $row['members']);
            sort($files);
            $key = $files === [] ? '#' . ($row['reason'] ?? 'unknown') : implode('+', $files);
            $index[$key] = $row;
        }
        return $index;
    }

    // ------------------------------------------------------------------ CLI harness (GitIgnoreHonoredTest laws)

    /** @param list<string> $args @param array<string,mixed> $cfgExtra */
    private function cli(array $args, array $cfgExtra = [], string $cacheTag = 'x', float $timeout = self::TIMEOUT_S): array
    {
        $cfgPath  = $this->tmp . '/cfg-' . $cacheTag . '.json';
        $cacheDir = $this->tmp . '/cache-' . $cacheTag;
        // Warm phases may pre-create the cache root (assertDirectoryExists);
        // a second cli() call on the same tag must reuse, not warn.
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0o775, true);
        }
        file_put_contents($cfgPath, (string) json_encode(['cache_dir' => $cacheDir] + $cfgExtra));

        $cmd  = array_merge([PHP_BINARY, self::repoRoot() . '/bin/phpdup'], $args, ['-c', $cfgPath]);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp);
        self::assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out      = '';
        $err      = '';
        $deadline = microtime(true) + $timeout;
        $outOpen  = true;
        $errOpen  = true;
        while ($outOpen || $errOpen) {
            $read = array_filter([$outOpen ? $pipes[1] : null, $errOpen ? $pipes[2] : null]);
            $w = $e = null;
            if ($read !== [] && stream_select($read, $w, $e, 1) > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 65536);
                    if ($stream === $pipes[1]) {
                        $out .= $chunk === false ? '' : $chunk;
                    } else {
                        $err .= $chunk === false ? '' : $chunk;
                    }
                }
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                self::fail(sprintf('phpdup child timed out after %.0fs: %s', $timeout, implode(' ', $args)));
            }
            if (@feof($pipes[1])) {
                $outOpen = false;
            }
            if (@feof($pipes[2])) {
                $errOpen = false;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'out' => $out, 'err' => $err, 'combined' => $out . $err];
    }

    private static function repoRoot(): string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/bin/phpdup')) {
                return $dir;
            }
            $dir = dirname($dir);
        }
        throw new \RuntimeException('cannot locate repo root from ' . __DIR__);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            if (is_link($entry) || is_file($entry)) {
                @unlink($entry);
            } elseif (is_dir($entry)) {
                @rmdir($entry);
            }
        }
        @rmdir($dir);
    }
}
