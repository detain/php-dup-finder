<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Architecture\Analyzers;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Architecture\Analyzers\SymbolDuplicationAnalyzer;
use Phpdup\Architecture\Finding;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\SymbolIndex;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 3.4 (S4): the duplicate-symbol evidence analyzer riding the
 * architectural-findings spine.
 *
 * The letter under test (improve_logic.md 3.4): same function name / class
 * short name / file basename defined in more than one place is FORCED into
 * comparison regardless of similarity, and the report shows the MEASURED
 * similarity either way — a merged pair is an honest note, a diverged
 * partner is the "same filename different logic" shadow evidence.
 */
final class SymbolDuplicationAnalyzerTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    // ── hand-built spine pins ─────────────────────────────────────────────

    private function block(
        string $id,
        string $file,
        ?string $name,
        ?string $class,
        array $bag,
        int $size = 6,
    ): Block {
        $b = new Block(
            file: $file,
            range: new LineRange(3, 9),
            kind: $class !== null ? 'method' : 'function',
            namespace: null,
            class: $class,
            name: $name,
            ast: new Node\Stmt\Return_(new Node\Scalar\String_($id)),
        );
        $b->id = $id;
        $b->structuralHash = 'h_' . $id;
        $b->ngramBag = $bag;
        $b->size = $size;
        return $b;
    }

    /** @param list<Block> $blocks */
    private function analyzer(array $blocks, float $threshold = 0.8): SymbolDuplicationAnalyzer
    {
        $index = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }
        $symbols = new SymbolIndex();
        $symbols->build($index);

        return new SymbolDuplicationAnalyzer(symbols: $symbols, similarityThreshold: $threshold);
    }

    public function testMergedPairFilesNoteOnTheSpine(): void
    {
        $a = $this->block('a', '/x/alpha/tools/vps_create.php', 'create_vps', null, ['k1' => 1, 'k2' => 2]);
        $b = $this->block('b', '/x/beta/tools/vps_create.php', 'create_vps', null, ['k1' => 1, 'k2' => 2]);

        // name route wins the pair key before basename (dedup priority),
        // so the trio of potential routes collapses to exactly one row.
        $findings = $this->analyzer([$a, $b])->analyze(new Cluster('c1', [$a, $b], 1.0, false));

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('SymbolDuplication', $f->analyzer);
        $this->assertSame(SymbolDuplicationAnalyzer::CODE_MERGED, $f->code);
        $this->assertSame(Finding::SEVERITY_NOTE, $f->severity);
        $this->assertStringContainsString('function-name route', $f->message);
        $this->assertStringContainsString('"create_vps"', $f->message);
        $this->assertStringContainsString('merged here', $f->message);
    }

    public function testDivergedSameNamePartnerIsWarningShadow(): void
    {
        $a = $this->block('a', '/x/alpha/tools/vps_create.php', 'create_vps', null, ['k1' => 1, 'k2' => 2]);
        $b = $this->block('b', '/x/beta/tools/vps_create.php', 'create_vps', null, ['z9' => 1, 'z8' => 2]);

        $findings = $this->analyzer([$a, $b])->analyze(new Cluster('c1', [$a], 1.0, false));

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame(SymbolDuplicationAnalyzer::CODE_SHADOW, $f->code);
        $this->assertSame(Finding::SEVERITY_WARNING, $f->severity, 'same-name divergence is the myadmin-grade case');
        $this->assertStringContainsString('same-symbol partner', $f->message);
        $this->assertStringContainsString('below the floor', $f->message);
        $this->assertStringContainsString('Same name, different logic', $f->message);
        $this->assertStringContainsString('measured jaccard 0.000', $f->message);
        $this->assertNotNull($f->suggestion);
    }

    public function testBasenameCrossSymbolPartnerStaysANote(): void
    {
        $a = $this->block('a', '/x/alpha/tools/vps_create.php', 'make_alpha', null, ['k1' => 1]);
        $b = $this->block('b', '/x/beta/tools/vps_create.php', 'make_beta', null, ['k1' => 1]);

        $findings = $this->analyzer([$a, $b])->analyze(new Cluster('c1', [$a], 1.0, false));

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame(SymbolDuplicationAnalyzer::CODE_SHADOW, $f->code);
        $this->assertSame(Finding::SEVERITY_NOTE, $f->severity, 'not the same symbol — comparison ran, no merge claimed');
        $this->assertStringContainsString('same-basename-file partner', $f->message);
        $this->assertNull($f->suggestion);
    }

    public function testTwinClassRouteCarriesMagicMethods(): void
    {
        // The name route stops-lists '__'; the class route is the magic
        // twins' only lane — same short class, different qualified,
        // same __construct — the B-12 RateLimitException shape.
        $a = $this->block('a', '/x/lib/RateLimitException.php', '__construct', 'Mod\\RateLimitException', ['k1' => 1, 'k2' => 2]);
        $b = $this->block('b', '/y/other/RateLimitException.php', '__construct', 'Other\\RateLimitException', ['k1' => 1, 'k2' => 2]);

        $findings = $this->analyzer([$a, $b])->analyze(new Cluster('c1', [$a, $b], 1.0, false));

        $this->assertCount(1, $findings);
        $this->assertSame(SymbolDuplicationAnalyzer::CODE_MERGED, $findings[0]->code);
        $this->assertStringContainsString('twin-class route', $findings[0]->message);
        $this->assertStringContainsString('Mod\\RateLimitException', $findings[0]->message);
    }

    public function testIdenticalPartnerRowsCollapseToOneFinding(): void
    {
        // p2/p3 share file+class+name (distinct ids only, so the name
        // route never pairs THEM together) — the shadow rows for member m
        // would otherwise double-print the same site.
        $m  = $this->block('m', '/x/one/tools/a.php', 'dup', null, ['k1' => 1]);
        $p2 = $this->block('p2', '/x/two/tools/b.php', 'dup', 'K', ['k9' => 1]);
        $p3 = $this->block('p3', '/x/two/tools/b.php', 'dup', 'K', ['k9' => 1]);

        $findings = $this->analyzer([$m, $p2, $p3])->analyze(new Cluster('c1', [$m], 1.0, false));

        $this->assertCount(1, $findings, 'identical messages must dedup');
    }

    public function testUnrelatedClusterEmitsNothing(): void
    {
        $a = $this->block('a', '/x/alpha/tools/vps_create.php', 'create_vps', null, ['k1' => 1, 'k2' => 2]);
        $b = $this->block('b', '/x/beta/tools/vps_create.php', 'create_vps', null, ['k1' => 1, 'k2' => 2]);
        $z = $this->block('z', '/x/gamma/only_here.php', 'loneWolf', null, ['k5' => 1]);

        $this->assertSame([], $this->analyzer([$a, $b, $z])->analyze(new Cluster('cz', [$z], 1.0, false)));
    }

    // ── end-to-end (stage-driven, hermetic tmp corpus) ───────────────────

    /** @return array{0:string,1:string,2:string} */
    private function vpsCorpus(): array
    {
        return [
            'alpha' => "<?php\nfunction tool_common(array \$row): array\n{\n    \$out = [];\n    foreach (\$row as \$k => \$v) {\n        \$out[\$k] = trim((string) \$v);\n    }\n    return \$out;\n}\nfunction create_vps(array \$spec): bool\n{\n    \$label = 'VPS created on alpha node';\n    \$disk = \$spec['disk'] * 1024;\n    \$ok = alpha_provision(\$spec['hostname'], \$disk, \$label);\n    audit_log('alpha_create', [\$spec['hostname'], \$disk]);\n    return \$ok;\n}\n",
            'beta'  => "<?php\nfunction tool_common(array \$row): array\n{\n    \$out = [];\n    foreach (\$row as \$k => \$v) {\n        \$out[\$k] = trim((string) \$v);\n    }\n    return \$out;\n}\nfunction create_vps(array \$spec): bool\n{\n    \$mail = new Notifier();\n    \$mail->send(\$spec['owner'], 'Welcome to your brand new box!');\n    beta_resize(\$spec['id'], \$spec['ram']);\n    return true;\n}\n",
            'gamma' => "<?php\nfunction tool_common(array \$row): array\n{\n    \$out = [];\n    foreach (\$row as \$k => \$v) {\n        \$out[\$k] = trim((string) \$v);\n    }\n    return \$out;\n}\nfunction create_vps(array \$spec): bool\n{\n    \$label = 'VPS created on gamma node';\n    \$disk = \$spec['disk'] * 1024;\n    \$ok = gamma_provision(\$spec['hostname'], \$disk, \$label);\n    audit_log('gamma_create', [\$spec['hostname'], \$disk]);\n    return \$ok;\n}\n",
        ];
    }

    /**
     * @return array{
     *     ids: list<string>,
     *     findings: list<array{0:string,1:string,2:string}>,
     *     index: BlockIndex
     * }
     */
    private function drivePipeline(bool $armed, ?string $dir = null): array
    {
        if ($dir === null) {
            $dir = sys_get_temp_dir() . '/phpdup-sym-e2e-' . bin2hex(random_bytes(6));
            $this->tmpDirs[] = $dir;
            foreach ($this->vpsCorpus() as $node => $code) {
                mkdir($dir . '/' . $node . '/tools', 0o777, true);
                file_put_contents($dir . '/' . $node . '/tools/vps_create.php', $code);
            }
        }

        $config = new Config(
            paths: [$dir],
            exclude: [],
            workers: 1,
            incremental: false,
            minClusterImpact: 0,
            symbolsForced: $armed,
        );
        $state = new PipelineState($config);
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, new NullOutput());
        (new RefactorStage(useCache: false))->run($state, new NullOutput());
        (new ReportStage(limit: 0, showStats: false))->run($state, new NullOutput());

        $ids = [];
        $findings = [];
        foreach ($state->report?->clusters ?? [] as $cluster) {
            $ids[] = $cluster->id;
            foreach ($cluster->architecturalFindings as $f) {
                if ($f->analyzer === 'SymbolDuplication') {
                    $findings[] = [$f->code, $f->severity, $f->message];
                }
            }
        }
        $this->assertNotNull($state->index);

        return ['ids' => $ids, 'findings' => $findings, 'index' => $state->index];
    }

    public function testOffRunCarriesNoSymbolFindingsAndIdenticalClusters(): void
    {
        $off = $this->drivePipeline(false);
        $on  = $this->drivePipeline(true);

        $this->assertSame([], $off['findings'], 'unarmed runs never construct the analyzer');
        $this->assertSame($off['ids'], $on['ids'], 'the forced lane may not reshuffle reported clusters here');
    }

    public function testOnRunSurfacesShadowAndMergedOnTheSpine(): void
    {
        $on = $this->drivePipeline(true);

        $shadow = array_values(array_filter($on['findings'], static fn (array $r): bool => $r[0] === SymbolDuplicationAnalyzer::CODE_SHADOW));
        $merged = array_values(array_filter($on['findings'], static fn (array $r): bool => $r[0] === SymbolDuplicationAnalyzer::CODE_MERGED));

        $this->assertNotEmpty($merged, 'the identical tool_common trio must file merged notes');
        $this->assertNotEmpty($shadow, 'the diverged beta create_vps must file shadow evidence');

        $shadowText = implode("\n", array_map(static fn (array $r): string => $r[1] . ' ' . $r[2], $shadow));
        $this->assertMatchesRegularExpression(
            '/warning .*create_vps.*(beta|vps_create\.php).*below the floor/s',
            $shadowText,
            'the myadmin-grade row: same name, different logic, measured below the floor',
        );
    }

    public function testForcedPairsRebuildDeterministicallyFromTheSameIndex(): void
    {
        // The spine law: the analyzer rebuilds the index from the same
        // resident data — two builds must hand back byte-equal pair lists,
        // or the evidence trail drifts from the cluster pass that made it.
        $run = $this->drivePipeline(true);

        $first = new SymbolIndex();
        $first->build($run['index']);
        $second = new SymbolIndex();
        $second->build($run['index']);

        $this->assertSame($first->forcedPairs(), $second->forcedPairs());
        $this->assertNotEmpty($first->forcedPairs());
    }

    public function testAnalyzerRowsAreDeterministicAcrossRuns(): void
    {
        // Same input dir, two full drives: the evidence trail must not
        // drift run to run (paths are fixed here — the point is the
        // ordering/selection law, not the scratch location).
        $dir = sys_get_temp_dir() . '/phpdup-sym-e2e-' . bin2hex(random_bytes(6));
        $this->tmpDirs[] = $dir;
        foreach ($this->vpsCorpus() as $node => $code) {
            mkdir($dir . '/' . $node . '/tools', 0o777, true);
            file_put_contents($dir . '/' . $node . '/tools/vps_create.php', $code);
        }

        $a = $this->drivePipeline(true, $dir);
        $b = $this->drivePipeline(true, $dir);

        $this->assertSame($a['findings'], $b['findings']);
    }
}
