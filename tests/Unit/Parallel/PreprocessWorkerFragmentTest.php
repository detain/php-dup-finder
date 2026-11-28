<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parallel\PreprocessWorker;

/**
 * Item 2.1 — the §5c mining leg of PreprocessWorker: one per-file walk
 * (inside BlockExtractor) yields the statement lists; the worker
 * normalizes each list ONCE, folds the per-statement sequence
 * (Util\StmtHash), decorates the fragments minted from that list with
 * their list-relative run key + prev/next witnesses, and ships the
 * sequences as ONE scalar 'mined' row per file (flavor stmtseq). The
 * row must be wire-safe (payload is a json STRING — objects never ride
 * the SOCKETPAIR wire) and the whole leg must be serial/streaming
 * identical.
 */
final class PreprocessWorkerFragmentTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdup_wfrag_' . uniqid('w', true);
        mkdir($this->tmpDir . '/cache', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/**/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir . '/cache');
        @rmdir($this->tmpDir);
    }

    private function writePhp(string $name, string $code): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $code);
        return $path;
    }

    /** @param list<string> $kinds */
    private function config(array $kinds): Config
    {
        return new Config(
            paths: [$this->tmpDir],
            exclude: [],
            minBlockSize: 1,
            allowedKinds: $kinds,
            cacheDir: $this->tmpDir . '/cache',
        );
    }

    /** @return list<string> */
    private function fragmentTuple(Block $b): string
    {
        return $b->kind . '|' . $b->range->start . '-' . $b->range->end
            . '|' . $b->fragmentPos . '|' . $b->fragmentSize
            . '|' . $b->fragmentKey . '|' . $b->fragmentPrev . '|' . $b->fragmentNext
            . '|' . ($b->fragmentWhole ? 'W' : '-');
    }

    public function testMinedRowShapeIsWireScalarAndCarriesTheSequence(): void
    {
        $path = $this->writePhp('two.php', <<<'PHP'
            <?php
            function alpha($id) { $r = q($id); $s = w($r); e($s); return $s; }
            function beta($id)  { $r = q($id); $s = w($r); e($s); return $id; }
            PHP);

        $rows = (new PreprocessWorker($this->config(['function', BlockExtractor::FRAGMENT_KIND])))->process([$path]);

        $mined = array_values(array_filter($rows, static fn (array $r): bool => $r['type'] === 'mined'));
        $this->assertCount(1, $mined, 'exactly one mined row per file — ONE generic row type (§5c)');
        $this->assertSame($path, $mined[0]['file']);
        $this->assertSame('stmtseq', $mined[0]['flavor']);
        $this->assertIsString($mined[0]['payload'], 'the payload is a scalar string: objects never ride the wire');

        $decoded = json_decode($mined[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded, 'both function bodies carry lists');
        foreach ($decoded as $entry) {
            $this->assertSame(['hashes', 'firstLine', 'lastLine', 'ownerKind'], array_keys($entry));
            $this->assertSame('function', $entry['ownerKind']);
            $this->assertCount(4, $entry['hashes']);
            foreach ($entry['hashes'] as $h) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $h, 'sha1 hex folds only');
            }
        }
    }

    public function testFragmentsAreDecoratedListRelatively(): void
    {
        $path = $this->writePhp('one.php', <<<'PHP'
            <?php
            function alpha($id) { $r = q($id); $s = w($r); e($s); return $s; }
            PHP);

        $rows = (new PreprocessWorker($this->config(['function', BlockExtractor::FRAGMENT_KIND])))->process([$path]);
        $mined = json_decode(array_values(array_filter($rows, static fn (array $r): bool => $r['type'] === 'mined'))[0]['payload'], true);
        $seq = $mined[0]['hashes'];

        /** @var list<Block> $frags */
        $frags = array_values(array_filter(
            array_map(static fn (array $r) => $r['block'] ?? null, $rows),
            static fn (?Block $b): bool => $b !== null && $b->kind === BlockExtractor::FRAGMENT_KIND,
        ));
        $this->assertNotEmpty($frags);
        foreach ($frags as $f) {
            $this->assertSame(
                implode('~', array_slice($seq, $f->fragmentPos, $f->fragmentSize)),
                $f->fragmentKey,
                'run key = the list sequence sliced at the run position',
            );
            $this->assertSame(
                $f->fragmentPos > 0 ? $seq[$f->fragmentPos - 1] : '',
                $f->fragmentPrev,
            );
            $next = $f->fragmentPos + $f->fragmentSize;
            $this->assertSame($next < count($seq) ? $seq[$next] : '', $f->fragmentNext);
        }
        // the whole-list run carries both edges empty (list edges)
        $whole = array_values(array_filter($frags, static fn (Block $b): bool => $b->fragmentPos === 0 && $b->fragmentSize === count($seq)));
        $this->assertNotEmpty($whole, 'the whole 4-statement list is a minted run');
        $this->assertSame('', $whole[0]->fragmentPrev);
        $this->assertSame('', $whole[0]->fragmentNext);
    }

    public function testCrossFunctionIdenticalRunsShareOneContentIdentity(): void
    {
        // The 2.1 done-when at the substrate level: two DIFFERENT
        // declarations carrying the same 4-statement body. Identity for
        // clustering is the run-local structuralHash (offset-invariant);
        // the list-relative fragmentKey depends on where the run sits in
        // its list (prefix numbering shifts it) and is deliberately NOT
        // the clustering identity.
        $a = $this->writePhp('a.php', "<?php\nfunction alpha() {\n    \$r = q(\$v);\n    \$s = w(\$r);\n    e(\$s);\n    return \$s;\n}\n");
        $b = $this->writePhp('b.php', "<?php\nfunction beta() {\n    \$x = 1;\n    \$r = q(\$v);\n    \$s = w(\$r);\n    e(\$s);\n    return \$s;\n}\n");

        $rowsA = (new PreprocessWorker($this->config(['function', BlockExtractor::FRAGMENT_KIND])))->process([$a]);
        $rowsB = (new PreprocessWorker($this->config(['function', BlockExtractor::FRAGMENT_KIND])))->process([$b]);

        $wholeA = $this->findFragment($rowsA, 4, 0);
        $runB   = $this->findFragment($rowsB, 4, 1); // same content, list position 1
        $this->assertNotNull($wholeA);
        $this->assertNotNull($runB);
        $this->assertSame($wholeA->structuralHash, $runB->structuralHash, 'equal content at different offsets is ONE identity');
        $this->assertTrue($wholeA->fragmentWhole, 'a.php run IS its whole list');
        $this->assertFalse($runB->fragmentWhole);
        $this->assertSame('', $wholeA->fragmentPrev);
        $this->assertNotSame('', $runB->fragmentPrev, 'b.php run has a real list neighbour');
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function findFragment(array $rows, int $size, int $pos): ?Block
    {
        foreach ($rows as $r) {
            $b = $r['block'] ?? null;
            if ($b instanceof Block && $b->kind === BlockExtractor::FRAGMENT_KIND
                && $b->fragmentSize === $size && $b->fragmentPos === $pos) {
                return $b;
            }
        }
        return null;
    }

    public function testDefaultPostureEmitsNoMinedRowsAndNoFragments(): void
    {
        $path = $this->writePhp('plain.php', "<?php\nfunction alpha() {\n    \$r = q(1);\n    \$s = w(\$r);\n    return \$s;\n}\n");
        // no 'fragment' in the roster: lists are not even collected
        $rows = (new PreprocessWorker($this->config(['function'])))->process([$path]);
        foreach ($rows as $r) {
            $this->assertNotSame('mined', $r['type'], 'the miner is demand-gated: default corpora emit no rows');
            if (($r['type'] ?? '') === 'block') {
                $this->assertNotSame(BlockExtractor::FRAGMENT_KIND, $r['block']->kind);
            }
        }
    }

    public function testProcessAndProcessStreamProduceIdenticalRows(): void
    {
        $a = $this->writePhp('s1.php', "<?php\nfunction alpha() {\n    \$r = q(\$v);\n    \$s = w(\$r);\n    e(\$s);\n    return \$s;\n}\n");
        $b = $this->writePhp('s2.php', "<?php\nfunction beta() {\n    \$r = q(\$v);\n    \$s = w(\$r);\n    e(\$s);\n    return \$s;\n}\n");
        $config = $this->config(['function', BlockExtractor::FRAGMENT_KIND]);
        $worker = new PreprocessWorker($config);

        $arrayRows = $worker->process([$a, $b]);
        $streamRows = iterator_to_array($worker->processStream([$a, $b]), false);

        $this->assertSame(
            array_map($this->rowFingerprint(...), $arrayRows),
            array_map($this->rowFingerprint(...), $streamRows),
            'serial and streaming agree structurally (objects freshly parsed carry no identity)',
        );
    }

    /** @param array<string,mixed> $row */
    private function rowFingerprint(array $row): string
    {
        if ($row['type'] === 'block') {
            return 'block|' . $this->fragmentTuple($row['block']);
        }
        if ($row['type'] === 'mined') {
            return 'mined|' . $row['flavor'] . '|' . $row['payload'];
        }
        return $row['type'] . '|' . ($row['file'] ?? '');
    }
}
