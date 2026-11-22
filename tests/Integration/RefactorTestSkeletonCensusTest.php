<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\RefactorTestReporter;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;

/**
 * r2 N-1 — the pin whose absence let 128/159 real-corpus skeletons ship
 * unparseable while every synthetic test stayed green: project the ACTUAL
 * hole vocabulary (sigil-carrying names, class-string types) by running the
 * real CLI over the real corpora, then php -l every emitted skeleton.
 *
 * Roots scanned: src/ (the repo self-scan the review ran) and tests/Fixtures/
 * (the golden corpora). Both must reach ZERO lint failures — the census
 * count itself is also asserted so the run cannot silently go vacuous.
 */
final class RefactorTestSkeletonCensusTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string)sys_get_temp_dir() . '/phpdup-skel-census-' . uniqid('', true);
        mkdir($this->tmp . '/cache', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function testSelfScanSkeletonsAllParse(): void
    {
        $this->census('src', 100);
    }

    public function testFixturesCorpusSkeletonsAllParse(): void
    {
        $this->census('tests/Fixtures', 1);
    }

    /**
     * output-S35 (php-dup-finder-output-007, CRITICAL) — the load-bearing
     * pin, driven through the REAL pipeline exactly as SEC re-proved it:
     * a scanned tree whose cluster's differing statement holds a concat
     * expression (prettyPrintExpr of any expression is quote-shaped, first
     * char === last char). The retired passthrough emitted
     * `'' . touch(canary) . ''` RAW into casesProvider(), where PHPUnit
     * evaluates it at COLLECTION time — a generator-side code-execution
     * sink. This test demands, on real emitted bytes:
     *   1. non-vacuity: the payload token DID reach the skeleton;
     *   2. the raw `=> ['' . touch` row shape is GONE;
     *   3. every skeleton lints;
     *   4. the provider COLLECTS under `disable_functions=touch` and
     *      returns the payload byte-for-byte as string data (the exact
     *      array PHPUnit would build);
     *   5. the canary file the payload names was never created.
     */
    public function testHostileQuoteShapedExprShipsAsDataNotLiveCode(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $this->tmp . '/hostile';
        $out = $this->tmp . '/hostile_out';
        mkdir($src, 0o775, true);
        mkdir($out, 0o775, true);
        $canary = $this->tmp . '/CANARY_MUST_NOT_EXIST';

        // Aligned bodies, one statement diverging in SHAPE: the seed's
        // whole concat becomes the hole's observed value (measured at the
        // live repro — a same-shape pair recurses to the string leaf and
        // never produces the sink shape).
        $commonHead = "<?php\nclass %s\n{\n    public function stamp(string \$tag): string\n    {\n"
            . "        \$log = strtolower(\$tag);\n        \$len = strlen(\$log);\n"
            . "        \$pad = str_repeat('.', \$len);\n        \$now = time();\n"
            . "        \$env = getenv('APP_ENV');\n";
        $commonTail = "        \$out = \$pad . '|' . \$log . '|' . \$env;\n"
            . "        error_log('stamp ' . \$out);\n        return \$out . \$v;\n    }\n}\n";
        file_put_contents($src . '/A.php', sprintf($commonHead, 'HostileA') . "        \$v = '' . touch('" . $canary . "') . '';\n" . $commonTail);
        file_put_contents($src . '/B.php', sprintf($commonHead, 'HostileB') . "        \$v = date('c');\n" . $commonTail);

        $config = $this->tmp . '/phpdup-hostile.json';
        file_put_contents($config, json_encode(['cache_dir' => $this->tmp . '/cache']) ?: '{}');
        $cmd = sprintf(
            '%s %s analyze %s --refactor-tests %s --min-impact 0 --max-df 0.5 --workers 1 --config %s --no-cache 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($repo . '/bin/phpdup'),
            escapeshellarg($src),
            escapeshellarg($out),
            escapeshellarg($config),
        );
        exec($cmd, $lines, $rc);
        $this->assertSame(0, $rc, 'hostile scan failed:\n' . implode("\n", array_slice($lines, -8)));

        $files = glob($out . '/*.php') ?: [];
        $this->assertNotEmpty($files, 'hostile fixture must emit at least one skeleton (vacuity crux)');

        $payloadSeen = false;
        foreach ($files as $f) {
            $contents = (string)file_get_contents($f);
            if (str_contains($contents, 'touch(')) {
                $payloadSeen = true;
                // The injection verbatim: an array opener handing the
                // payload back as live code. Must not exist.
                $this->assertStringNotContainsString("=> ['' . touch", $contents, "raw sink shape survived in {$f}");
            }
            $lint = shell_exec('php -l ' . escapeshellarg($f) . ' 2>&1');
            $this->assertStringContainsString('No syntax errors', (string)$lint, "unparseable skeleton {$f}");
        }
        $this->assertTrue($payloadSeen, 'fixture no longer drives an expression-shaped observed value — the pin went vacuous');

        // 4. Real collection: build the provider array exactly as PHPUnit
        //    does, with touch() DISABLED — code-shaped data cannot run, and
        //    a resurrected sink fatals the collector.
        $runner = $this->tmp . '/collect.php';
        file_put_contents($runner, implode("\n", [
            '<?php',
            'require $argv[2];',           // vendor autoloader (the skeleton extends TestCase)
            'require $argv[1];',
            'preg_match("/final class (\\w+) extends/", file_get_contents($argv[1]), $m);',
            '$rows = call_user_func("Refactored\\\\Tests\\\\" . $m[1] . "::casesProvider");',
            'foreach ($rows as $row) { foreach ($row as $v) {',
            '    if (is_string($v)) { fwrite(STDOUT, $v . "\n"); }',
            '    elseif ($v !== null && !is_bool($v)) { fwrite(STDOUT, "[data:" . gettype($v) . "]\n"); }',
            '}}',
        ]));
        $scriptOut = [];
        exec(
            sprintf(
                '%s -d disable_functions=touch %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($runner),
                escapeshellarg($files[0]),
                escapeshellarg($repo . '/vendor/autoload.php'),
            ),
            $scriptOut,
            $collectRc,
        );
        $this->assertSame(0, $collectRc, 'provider collection failed:\n' . implode("\n", $scriptOut));
        $this->assertContains(
            "'' . touch('" . $canary . "') . ''",
            $scriptOut,
            'the payload must round-trip through casesProvider() byte-for-byte as string data',
        );

        // 5. And it never EXECUTED: the canary it names stays absent.
        $this->assertFileDoesNotExist($canary, 'collection executed the payload — the sink is back');
    }

    // ── output-S36 (php-dup-finder-output-033): the collectability venue ─────
    //
    // The census's older legs prove the skeletons PARSE. The finding's
    // second half (statement repr in a `bool` slot) parses perfectly — it
    // dies at EXECUTION of the declared signature. This leg closes the
    // gap the way the acceptance words it: every skeleton, organic
    // (fixtures scan) and deterministic (writeTo on a Report), is
    //   1. declared-class == filename stem == a valid PHP identifier;
    //   2. loaded (require) under strict_types in a child;
    //   3. its provider collected and EVERY row splatted into a stub
    //      with the skeleton's own declared parameter types.
    // A `true`-shaped string in a bool column fatals leg 3 with a real
    // TypeError (the exact production failure the finding describes);
    // a raw odd id cannot even be captured by the class regex (leg 1).
    // Both directions proven: passes on the fixed corpus, red on either
    // arm neutered (mutants M1/M2 — see impl report).
    public function testCorpusSkeletonsCollectAgainstStubSignatures(): void
    {
        $repo = dirname(__DIR__, 2);
        $outDir = $this->tmp . '/collect_fixtures';
        mkdir($outDir, 0o775, true);
        $config = $this->tmp . '/phpdup-collect.json';
        file_put_contents($config, json_encode(['cache_dir' => $this->tmp . '/cache']) ?: '{}');
        $cmd = sprintf(
            '%s %s analyze %s --refactor-tests %s --min-impact 1 --workers 1 --config %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($repo . '/bin/phpdup'),
            escapeshellarg($repo . '/tests/Fixtures'),
            escapeshellarg($outDir),
            escapeshellarg($config),
        );
        exec($cmd, $lines, $rc);
        $this->assertSame(0, $rc, 'fixtures scan failed:\n' . implode("\n", array_slice($lines, -8)));
        $corpusFiles = glob($outDir . '/*.php') ?: [];
        $this->assertNotEmpty($corpusFiles, 'corpus leg went vacuous');

        // Deterministic S36 artifacts through the PUBLIC emitter, in
        // THEIR OWN directory so the collector asserts exact counts
        // (files=2, rows=5, boolcols=3) without depending on how the
        // organic corpus happens to drift.
        // (A) optional_block cluster across three members + one literal
        //     int companion: present → true; degraded-gap (flagged hole,
        //     so no false claim allowed) → annotated null riding into the
        //     bool column honestly; second present member → true again.
        $opt = new Hole('__O0', 'optional_block', ["if (\$ok) {\n    go();\n}", Hole::MISSING, 'echo 2;'], true);
        $opt->inferredType = 'bool';
        $opt->suggestedName = '$include_go';
        $int = new Hole('__P0', 'literal', ['1', '2', '3'], false);
        $int->inferredType = 'int';
        $int->suggestedName = '$count';
        $optional = new Cluster('S36_BOOL_ARMS', [$this->blk('t1.php'), $this->blk('t2.php'), $this->blk('t3.php')], 1.0, false);
        $optional->holes = [$opt, $int];
        // (B) odd legacy id whose RAW bytes previously reached the class
        //     declaration — the child's require() is the lint+stem proof.
        $odd = new Cluster("0-bad id|'x'", [$this->blk('t1.php'), $this->blk('t2.php')], 1.0, false);
        $odd->holes = [new Hole('__P0', 'literal', ['1', '2'])];
        $synthDir = $this->tmp . '/collect_synth';
        mkdir($synthDir, 0o775, true);
        (new RefactorTestReporter())->writeTo(
            new Report(2, 2, 0, [$optional, $odd], Config::defaults([])),
            $synthDir,
        );

        $runner = $this->tmp . '/collect.php';
        file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
// PHPUnit's collection step, modeled — and STRICT: this harness declares
// strict_types exactly like the skeletons do, so splatting a row into the
// stub is a byte-faithful replay of what the generated test would do to a
// real extracted function. A statement repr in a bool slot TypeErrors.
require $argv[1]; // vendor/autoload (the skeletons extend TestCase)
$dir = $argv[2];
$files = 0; $rows = 0; $boolCols = 0; $errs = [];
foreach (glob($dir . '/*.php') ?: [] as $f) {
    $files++;
    $src = (string)file_get_contents($f);
    if (preg_match('/final class (\w+) extends/', $src, $cm) !== 1) {
        $errs[] = "$f: no well-formed class declaration";
        continue;
    }
    if (basename($f, '.php') !== $cm[1]) {
        $errs[] = "$f: declared class {$cm[1]} != filename stem";
        continue;
    }
    require $f;
    $cls = 'Refactored\\Tests\\' . $cm[1];
    if (!class_exists($cls)) { $errs[] = "$f: {$cls} not loadable"; continue; }
    $params = (new ReflectionMethod($cls, 'testAbstractionMatchesEachMember'))->getParameters();
    $table = $cls::casesProvider();
    if ($table === []) { $errs[] = "$f: empty provider"; continue; }
    foreach ($table as $label => $row) {
        $rows++;
        if (count($row) !== count($params)) { $errs[] = "$f: {$label}: arity mismatch"; continue; }
        foreach ($params as $i => $p) {
            $t = (string)$p->getType();
            $v = $row[$i];
            if ($t === 'bool') {
                $boolCols++;
                // null is allowed: the S27/S35 honest annotation on a
                // degraded/unrecorded row (the skeleton then marks
                // incomplete) — but a STRING in this column is precisely
                // the output-033 bug.
                if ($v !== null && !is_bool($v)) { $errs[] = "$f: {$label}: " . gettype($v) . " value in bool column"; }
                continue;
            }
            // Only CLOSED scalar declarations are adjudicated — mixed /
            // union / object slots are other lanes' declared territory.
            if ($v === null) { continue; } // honest gap marker on any column
            if ($t === 'int' && !is_int($v)) { $errs[] = "$f: {$label}: " . gettype($v) . " in int column"; }
            elseif ($t === 'string' && !is_string($v)) { $errs[] = "$f: {$label}: " . gettype($v) . " in string column"; }
        }
    }
}
fwrite(STDOUT, "files={$files} rows={$rows} boolcols={$boolCols}\n");
if ($errs !== []) { fwrite(STDERR, implode("\n", $errs) . "\n"); exit(1); }
PHP);
        $collect = function (string $dir) use ($runner, $repo): array {
            $out = [];
            exec(
                sprintf(
                    '%s %s %s %s 2>&1',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg($runner),
                    escapeshellarg($repo . '/vendor/autoload.php'),
                    escapeshellarg($dir),
                ),
                $out,
                $rc,
            );
            return [$rc, implode("\n", $out)];
        };

        // Synthetic leg — the deterministic contract (three bool-column
        // rows true/null/true, stem-equality on the odd id, loadability
        // of every declaration):
        [$synthRc, $synthOut] = $collect($synthDir);
        $this->assertSame(0, $synthRc, "synthetic skeleton collection failed:\n" . $synthOut);
        $this->assertSame(
            'files=2 rows=5 boolcols=3',
            trim($synthOut),
            'the deterministic S36 artifacts must collect with these exact counts',
        );

        // Corpus leg — the organic contract: the real pipeline's own
        // optional_block cluster (tests/Fixtures/optional/Pair.php,
        // measured 2 bool columns x 2 rows) must drive the emitted
        // bool slots CLEAN through the same child. This is the leg the
        // neutered-P2 mutant reddens through real pipeline bytes, not
        // just hand-built holes.
        [$corpusRc, $corpusOut] = $collect($outDir);
        $this->assertSame(0, $corpusRc, "corpus skeleton collection failed:\n" . $corpusOut);
        $this->assertSame(
            1,
            preg_match('/files=(\d+) rows=(\d+) boolcols=(\d+)/', $corpusOut, $s),
            'collector summary missing:\n' . $corpusOut,
        );
        $this->assertGreaterThanOrEqual(count($corpusFiles), (int)$s[1], 'every corpus skeleton must be collected');
        $this->assertGreaterThanOrEqual(2, (int)$s[2], 'corpus leg went vacuous on rows');
        $this->assertGreaterThanOrEqual(4, (int)$s[3], 'corpus must still emit its organic bool columns (Pair.php) — if this trips, the corpus changed; re-derive, do not relax');

        // The odd-id artifact specifically: emitted under a stem that the
        // child matched leg 1 with (a raw `0-bad id|'x'` class line could
        // not), and its file name is identifier-safe.
        $this->assertFileExists($synthDir . '/Cluster0_bad_id__x_Test.php');
    }

    /** @param int $minFiles the run must at least emit this many skeletons */
    private function census(string $root, int $minFiles): void
    {
        $repo = dirname(__DIR__, 2);
        $outDir = $this->tmp . '/rt_' . str_replace('/', '_', $root);
        mkdir($outDir, 0o775, true);
        $config = $this->tmp . '/phpdup.json';
        file_put_contents($config, json_encode(['cache_dir' => $this->tmp . '/cache']) ?: '{}');

        $cmd = sprintf(
            '%s %s analyze %s --refactor-tests %s --min-impact 1 --workers 1 --config %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($repo . '/bin/phpdup'),
            escapeshellarg($repo . '/' . $root),
            escapeshellarg($outDir),
            escapeshellarg($config),
        );
        exec($cmd, $lines, $rc);
        $this->assertSame(0, $rc, 'scan of ' . $root . " failed:\n" . implode("\n", array_slice($lines, -8)));

        $files = glob($outDir . '/*.php') ?: [];
        $this->assertGreaterThanOrEqual(
            $minFiles,
            count($files),
            'census must exercise the real hole vocabulary, got ' . count($files) . ' skeletons from ' . $root,
        );

        // One shell, every file: exactly the review's find-and-lint census.
        $lintCmd = sprintf(
            'for f in %s/*.php; do php -l "$f" > /dev/null 2>&1 || echo "$f"; done',
            escapeshellarg($outDir),
        );
        $broken = array_values(array_filter(explode("\n", trim((string)shell_exec($lintCmd)))));
        $this->assertSame([], $broken, 'unparseable skeletons in ' . $root . ' census');

        // And the specific defect token must not even appear in a header.
        foreach ($files as $f) {
            $contents = (string)file_get_contents($f);
            if (preg_match('/function testAbstractionMatchesEachMember\(([^)]*)\)/', $contents, $m) === 1) {
                $this->assertStringNotContainsString(
                    '$$',
                    $m[1],
                    "double-sigil header survived in {$f}",
                );
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    /** output-S36: a minimal real Block (id stays '') for synthetic clusters. */
    private function blk(string $file): Block
    {
        $stmts = (new AstParser())->parseCode('<?php $x;');
        return new Block(
            file: $file,
            range: new LineRange(1, 1),
            kind: 'method',
            namespace: null, class: null, name: null,
            ast: $stmts[0],
        );
    }
}
