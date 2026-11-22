<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use PhpParser\Node\Stmt\Nop;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Reporting\DeclaredTwinAnalyzer;
use Phpdup\Util\LineRange;

/**
 * Item 4.3 — declared-twin checker. Fixture corpus is REAL parseable
 * files (the header-comment trap only bites when comments are read off
 * whole-file statements) paired with a synthetic BlockIndex carrying
 * the retained bags the measurement runs on.
 */
final class DeclaredTwinAnalyzerTest extends TestCase
{
    private string $tmp;

    /** 10 shared grams + one unique each => Jaccard 10/12 ≈ 0.833 ≥ .8 */
    private const NEAR_A = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ua1'];
    private const NEAR_B = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ub1'];
    /** disjoint-ish: Jaccard 2/20 = 0.1 < .8 */
    private const FAR = ['d1','d2','d3','d4','d5','d6','d7','d8','d9','d10'];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-decltwin-' . uniqid();
        mkdir($this->tmp, 0o775, true);

        $nearBody = "<?php\n%s\nfunction %s(array \$rows): float\n{\n    \$sum = 0.0;\n    foreach (\$rows as \$row) {\n        \$sum += \$row['amount'] * \$row['rate'];\n    }\n    return round(\$sum, 2);\n}\n";

        file_put_contents($this->tmp . '/alpha.php', sprintf($nearBody, "/**\n * Mirror of beta.php - a true twin.\n */", 'report_alpha'));
        file_put_contents($this->tmp . '/beta.php', "<?php\nfunction report_beta(array \$rows): float\n{\n    \$sum = 0.0;\n    foreach (\$rows as \$row) {\n        \$sum += \$row['amount'] * \$row['rate'];\n    }\n    return round(\$sum, 2);\n}\n");
        file_put_contents($this->tmp . '/gamma.php', sprintf($nearBody, "/**\n * Keep in sync with alpha.php\n */", 'report_gamma') . "\n/* d1 d2 d3 d4 d5 d6 d7 d8 d9 d10 unique */");
        file_put_contents($this->tmp . '/delta.php', sprintf($nearBody, "/**\n * Ported from ghost/nowhere.php\n */", 'report_delta'));
        file_put_contents($this->tmp . '/epsilon.php', sprintf($nearBody, "/**\n * This loosely mirrors the CSS box model semantics.\n */", 'report_epsilon'));
        file_put_contents($this->tmp . '/zeta.php', sprintf($nearBody, "/**\n * @phpdup-ignore Fabc1234567890ab vendored copy, divergence accepted\n */", 'report_zeta'));
        file_put_contents($this->tmp . '/eta.php', sprintf($nearBody, "//\n// Twin of report_beta() — a symbol-anchored claim.\n//", 'report_eta'));
        file_put_contents($this->tmp . '/theta.php', sprintf($nearBody, "/**\n * Byte-identical to beta.php\n */", 'report_theta'));
        // output-055: the same directive text twice in one comment — an
        // occurrence-aware line calc must number the two rows 3 and 4,
        // not 3 and 3.
        file_put_contents($this->tmp . '/iota.php', sprintf($nearBody, "/**\n * @phpdup-ignore Fdup1234567890ab vendored copy\n * @phpdup-ignore Fdup1234567890ab vendored copy\n */", 'report_iota'));
        // output-S34 (output-017): the harvest is token-level, so a claim
        // inside a function body is reached too — while a string LITERAL
        // wearing the same shape must never mint a row.
        file_put_contents($this->tmp . '/kappa.php', "<?php\nfunction report_kappa(array \$rows): float\n{\n    // Mirror of beta.php - a body-level claim.\n    \$note = 'Mirror of nowhere.php';\n    \$sum = 0.0;\n    foreach (\$rows as \$row) {\n        \$sum += \$row['amount'] * \$row['rate'];\n    }\n    return round(\$sum, 2);\n}\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*.php') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    /** @param list<string> $grams */
    private function block(string $file, string $name, array $grams, string $hash = ''): Block
    {
        $b = new Block(
            file: $this->tmp . '/' . $file,
            range: new LineRange(1, 10),
            kind: 'function',
            namespace: null,
            class: null,
            name: $name,
            ast: new Nop(),
        );
        $b->id = $file . '.b1';
        $b->structuralHash = $hash;
        $b->canonical = $b->ast;
        $b->ngramBag = array_fill_keys($grams, 1);
        return $b;
    }

    private function index(): BlockIndex
    {
        $index = new BlockIndex();
        // theta shares beta's structuralHash: the exact-twin fast path.
        $theta = $this->block('theta.php', 'report_theta', self::FAR, 'hash-beta-exact');
        $beta  = $this->block('beta.php',  'report_beta',  self::NEAR_B, 'hash-beta-exact');
        foreach ([
            $this->block('alpha.php', 'report_alpha', self::NEAR_A),
            $beta,
            $this->block('gamma.php', 'report_gamma', self::FAR),
            $this->block('delta.php', 'report_delta', self::FAR),
            $this->block('epsilon.php', 'report_epsilon', self::FAR),
            $this->block('zeta.php', 'report_zeta', self::FAR),
            $this->block('eta.php', 'report_eta', self::NEAR_A),
            $theta,
            $this->block('iota.php', 'report_iota', self::FAR),
            $this->block('kappa.php', 'report_kappa', self::NEAR_A),
        ] as $b) {
            $index->add($b);
        }
        return $index;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function rowFor(array $rows, string $fileBasename): ?array
    {
        foreach ($rows as $row) {
            if (basename((string) $row['file']) === $fileBasename) {
                return $row;
            }
        }
        return null;
    }

    private function analyzeNoClusters(): array
    {
        return (new DeclaredTwinAnalyzer($this->index()))->analyze([]);
    }

    public function testTrueMirrorClaimHoldsAgainstMeasuredBags(): void
    {
        $rows = $this->analyzeNoClusters();
        $row = $this->rowFor($rows, 'alpha.php');

        $this->assertNotNull($row, 'the "Mirror of beta.php" header must be harvested');
        $this->assertSame('claim', $row['kind']);
        $this->assertSame('beta.php', $row['reference']);
        $this->assertStringEndsWith('/beta.php', (string) $row['target_file'], 'target resolves to the corpus file');
        $this->assertIsFloat($row['similarity']);
        $this->assertGreaterThanOrEqual(0.8, (float) $row['similarity']);
        $this->assertSame('holds', $row['verdict']);
    }

    public function testExactHashTwinReportsSimilarityOne(): void
    {
        $rows = $this->analyzeNoClusters();
        $row = $this->rowFor($rows, 'theta.php');

        $this->assertNotNull($row);
        // Num::json collapses whole values to int bytes (version-invariant
        // rendering): an exact twin reports similarity as int 1, not float 1.0.
        $this->assertSame(1, $row['similarity']);
        $this->assertSame('holds', $row['verdict'], 'structuralHash equality is the byte-identical truth');
    }

    public function testSymbolAnchoredClaimResolvesAndHolds(): void
    {
        $rows = $this->analyzeNoClusters();
        $row = $this->rowFor($rows, 'eta.php');

        $this->assertNotNull($row, 'line comments are harvested too, not only doc blocks');
        $this->assertSame('report_beta()', $row['reference']);
        $this->assertStringEndsWith('beta.php', (string) $row['target_file']);
        $this->assertSame('holds', $row['verdict']);
    }

    public function testDriftedClaimIsFlaggedFalse(): void
    {
        $rows = $this->analyzeNoClusters();
        $row = $this->rowFor($rows, 'gamma.php');

        $this->assertNotNull($row);
        $this->assertLessThan(0.8, (float) $row['similarity']);
        $this->assertSame('drifted', $row['verdict'], 'FALSE claims are the point of item 4.3');
    }

    public function testUnresolvableReferenceStaysHonest(): void
    {
        $rows = $this->analyzeNoClusters();
        $row = $this->rowFor($rows, 'delta.php');

        $this->assertNotNull($row);
        $this->assertNull($row['target_file']);
        $this->assertNull($row['similarity']);
        $this->assertSame('unresolved', $row['verdict']);
    }

    public function testKeywordWithoutReferenceDeclaresNothing(): void
    {
        foreach ($this->analyzeNoClusters() as $row) {
            $this->assertNotSame('epsilon.php', basename((string) $row['file']),
                'prose that merely contains the keyword must not mint a row (reference required)');
        }
    }

    public function testIgnoreDirectiveIsHarvestedNotApplied(): void
    {
        $rows = $this->analyzeNoClusters();
        $row = $this->rowFor($rows, 'zeta.php');

        $this->assertNotNull($row);
        $this->assertSame('directive', $row['kind']);
        $this->assertSame('Fabc1234567890ab', $row['finding_id']);
        $this->assertSame('harvested', $row['verdict'], 'application is banked for the Config/Command leg (HANDOFF)');
    }

    public function testDuplicateIgnoreDirectivesGetDistinctCorrectLines(): void
    {
        $lines = [];
        foreach ($this->analyzeNoClusters() as $row) {
            if (basename((string) $row['file']) !== 'iota.php') {
                continue;
            }
            $this->assertSame('directive', $row['kind']);
            $this->assertSame('@phpdup-ignore', $row['keyword']);
            $this->assertSame('Fdup1234567890ab', $row['finding_id']);
            $this->assertSame('harvested', $row['verdict']);
            $lines[] = $row['line'];
        }

        $this->assertCount(2, $lines, 'both identical directive lines are harvested');
        $this->assertSame([3, 4], $lines,
            'occurrence-aware calc: the first is on line 3, the copy on line 4 — '
            . 'strpos-of-first-occurrence used to number both 3 (output-055)');
    }

    public function testRowsAreSortedByFileAndLine(): void
    {
        $keys = array_map(static fn (array $r): string => $r['file'] . ':' . $r['line'], $this->analyzeNoClusters());
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys, 'the table is byte-stable across scan orders');
    }

    public function testDriftedClaimantClusterGainsWarningFinding(): void
    {
        $index = $this->index();
        $analyzer = new DeclaredTwinAnalyzer($index);

        $gamma = null;
        foreach ($index->all() as $b) {
            if (basename($b->file) === 'gamma.php') {
                $gamma = $b;
            }
        }
        $this->assertNotNull($gamma);

        $cluster = new Cluster('F01234567890abcd', [$gamma], 0.9, false);
        $rows = $analyzer->analyze([$cluster]);
        $analyzer->annotateClusters([$cluster], $rows);

        $codes = array_map(static fn ($f): string => $f->code, $cluster->architecturalFindings);
        $this->assertContains('declared-twin-drift', $codes,
            'drift must be visible where reviewers already see findings');

        // A holds-claim cluster is untouched.
        $alpha = null;
        foreach ($index->all() as $b) {
            if (basename($b->file) === 'alpha.php') {
                $alpha = $b;
            }
        }
        $this->assertNotNull($alpha);
        $quiet = new Cluster('Fffffffffffffffff', [$alpha], 0.9, false);
        $rows2 = $analyzer->analyze([$quiet]);
        $analyzer->annotateClusters([$quiet], $rows2);
        $this->assertSame([], $quiet->architecturalFindings);
    }

    public function testClusterIdLinksOnClaimRows(): void
    {
        $index = $this->index();
        $analyzer = new DeclaredTwinAnalyzer($index);
        $gamma = null;
        foreach ($index->all() as $b) {
            if (basename($b->file) === 'gamma.php') {
                $gamma = $b;
            }
        }
        $cluster = new Cluster('F01234567890abcd', [$gamma], 0.9, false);
        $rows = $analyzer->analyze([$cluster]);
        $row = $this->rowFor($rows, 'gamma.php');

        $this->assertNotNull($row);
        $this->assertSame('F01234567890abcd', $row['cluster_id']);
    }

    /**
     * output-S34 (php-dup-finder-output-017): the claim harvest reads
     * comments off the token stream, so a claim inside a function body
     * is reached — and a string literal wearing the claim shape is NOT,
     * because it is no comment. (The old statement-tree read missed the
     * former and the naive raw-text read would mint the latter.)
     */
    public function testBodyCommentClaimIsHarvestedAndStringLiteralIsNot(): void
    {
        $kappa = array_values(array_filter(
            $this->analyzeNoClusters(),
            static fn (array $row): bool => basename((string) $row['file']) === 'kappa.php',
        ));

        $this->assertCount(1, $kappa, 'the body comment mints one row; the mirrored string literal mints none');
        $row = $kappa[0];
        $this->assertSame('claim', $row['kind']);
        $this->assertSame('beta.php', $row['reference']);
        $this->assertSame(4, $row['line'], 'token start line of the body comment');
        $this->assertStringEndsWith('/beta.php', (string) $row['target_file']);
        $this->assertSame('holds', $row['verdict']);
    }

    /**
     * The --no-cache witness for php-dup-finder-output-017, pinned
     * structurally because the parse path is not merely skipped on cold
     * runs — it is gone: the analyzer carries no cache input and its
     * source contains no whole-file parse or tree-deserialize call sites
     * at all (a counting subclass could only prove one run; this pin
     * proves every run and fails loud if a tree load ever returns).
     */
    public function testAnalyzerCarriesNoTreeLoadOrCachePath(): void
    {
        $reflection = new \ReflectionClass(DeclaredTwinAnalyzer::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        foreach (['parseFile', 'AstParser', 'AstCache', 'loadStatements'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source,
                "the token-level harvest must never regain a whole-file tree load via '$forbidden' (output-017)");
        }

        foreach ($reflection->getConstructor()->getParameters() as $param) {
            $this->assertNotSame('cacheDir', $param->getName(),
                'no disk-cache input: a cold run is the only run (output-017)');
        }
    }

    /**
     * output-S34 (php-dup-finder-output-028): reference resolution reads
     * shared per-run maps — one index pass lives in analyze(), and the
     * resolvers themselves scan no corpus.
     */
    public function testReferenceResolutionIsMapLookupNotPerClaimScan(): void
    {
        $file = (string) (new \ReflectionClass(DeclaredTwinAnalyzer::class))->getFileName();
        $source = (string) file_get_contents($file);
        $this->assertSame(1, substr_count($source, '$this->index->all()'),
            'exactly one corpus pass per run (the fileBlocks grouping)');

        $method = new \ReflectionMethod(DeclaredTwinAnalyzer::class, 'resolveReference');
        $body = implode('', array_slice(
            (array) file($file),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
        $this->assertStringNotContainsString('$this->index->all()', $body,
            'the resolver answers from the shared maps, never by rescanning the index (output-028)');
    }
}
