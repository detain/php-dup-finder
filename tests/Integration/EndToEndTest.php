<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Index\BlockIndex;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\ParameterSynthesizer;
use Phpdup\Refactor\PatternRecognizer;
use Phpdup\Refactor\SignatureBuilder;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Scanning\FileScanner;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;

final class EndToEndTest extends TestCase
{
    public function testFinderProducesExpectedClustersOnFixtureCorpus(): void
    {
        $fixtureRoot = __DIR__ . '/../Fixtures';

        $config = new Config(
            paths: [$fixtureRoot],
            exclude: [],
            minBlockSize: 5,
            normalizationMode: 'aggressive',
            similarityThreshold: 0.7,
            treeThreshold: 0.7,
            minClusterImpact: 5,
        );

        $report = $this->runPipeline($config);

        $this->assertGreaterThan(0, count($report->clusters), 'expected at least one cluster');

        // Notify cluster: 3 members, threshold + role holes
        $notifyCluster = $this->findClusterTouching($report, 'notify/Notify.php');
        $this->assertNotNull($notifyCluster, 'notify cluster missing');
        $this->assertSame(3, $notifyCluster->size());
        $this->assertNotEmpty($notifyCluster->holes, 'notify cluster should have holes');

        // SQL cluster: 3 nearly-identical findById, table name should be a hole
        $sqlCluster = $this->findClusterTouching($report, 'sql/Repos.php');
        $this->assertNotNull($sqlCluster, 'sql cluster missing');
        $this->assertGreaterThanOrEqual(2, $sqlCluster->size());

        // Unrelated/Unrelated.php should not appear in any cluster
        foreach ($report->clusters as $c) {
            foreach ($c->members as $m) {
                $this->assertStringNotContainsString('unique/Unrelated.php', $m->file,
                    'Unrelated.php must not cluster with anything');
            }
        }
    }

    private function findClusterTouching(Report $report, string $pathSubstring): ?\Phpdup\Clustering\Cluster
    {
        foreach ($report->clusters as $c) {
            foreach ($c->members as $m) {
                if (str_contains($m->file, $pathSubstring)) {
                    return $c;
                }
            }
        }
        return null;
    }

    private function runPipeline(Config $config): Report
    {
        $scanner = new FileScanner($config->exclude);
        $parser = new AstParser();
        $extractor = new BlockExtractor($config->minBlockSize, $config->maxBlockSize);
        $normalizer = new Normalizer($config->normalizationMode);
        $hasher = new SubtreeHasher();
        $fingerprinter = new NgramFingerprint($config->ngramSize);

        $blocks = [];
        $files = 0;
        foreach ($config->paths as $root) {
            foreach ($scanner->scan($root) as $path) {
                $files++;
                $stmts = $parser->parseFile($path);
                if ($stmts === null) continue;
                foreach ($extractor->extract($path, $stmts) as $b) {
                    $normalizer->normalize($b);
                    $b->structuralHash = $hasher->hash($b->canonical);
                    $b->ngramBag = $fingerprinter->fingerprint($b->canonical);
                    $b->id = substr($b->structuralHash, 0, 8) . '_' . count($blocks);
                    $blocks[] = $b;
                }
            }
        }
        $index = new BlockIndex();
        foreach ($blocks as $b) $index->add($b);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: $config->similarityThreshold,
            treeThreshold: $config->treeThreshold,
            maxDocumentFrequency: $config->maxDocumentFrequency,
        );
        $clusters = $clusterer->cluster($index);
        $au = new AntiUnifier();
        $ps = new ParameterSynthesizer();
        $sb = new SignatureBuilder();
        $pr = new PatternRecognizer();
        foreach ($clusters as $c) {
            $au->unify($c);
            $ps->synthesize($c);
            $sb->buildSignature($c);
            $pr->tag($c);
        }
        $clusters = (new Ranker($config->minClusterImpact))->rank($clusters);
        return new Report($files, count($blocks), 0, $clusters, $config);
    }
}
