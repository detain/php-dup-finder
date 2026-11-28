<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\BaselineDisposition;
use Phpdup\Reporting\BaselineFilter;
use Phpdup\Reporting\BaselineStore;
use PHPUnit\Framework\TestCase;
use Phpdup\Util\LineRange;

/**
 * Item 4.4 APPLY half: the main/appendix split. Acceptance sources
 * (registry dispositions > @phpdup-ignore directives > phpdup.json
 * accepted list), fail-open resurface semantics, and the passthrough
 * default are all pinned here.
 */
final class BaselineFilterTest extends TestCase
{
    /** @param list<string> $files */
    private function cluster(string $id, array $files, int $impact = 10): Cluster
    {
        $blocks = [];
        foreach ($files as $i => $file) {
            $block = new Block(
                file: $file,
                range: new LineRange(1, 10),
                kind: 'method',
                namespace: null,
                class: null,
                name: 'm' . $i,
                ast: new Node\Stmt\Nop(),
            );
            $block->structuralHash = $id . '-h' . $i;
            $blocks[] = $block;
        }
        $cluster = new Cluster($id, $blocks, 1.0, false);
        $cluster->impact = $impact;
        return $cluster;
    }

    /** @return array<string, mixed> */
    private function directive(string $file, string $id, string $reason = ''): array
    {
        return [
            'file' => $file,
            'kind' => 'directive',
            'keyword' => '@phpdup-ignore',
            'reference' => $reason,
            'line' => 7,
            'comment' => '@phpdup-ignore ' . $id,
            'finding_id' => $id,
        ];
    }

    public function testEmptyRegistryPassthrough(): void
    {
        $clusters = [$this->cluster('Fa1', ['src/A.php']), $this->cluster('Fa2', ['src/B.php'])];
        $split = (new BaselineFilter())->split($clusters, [], [], []);

        $this->assertCount(2, $split['main']);
        $this->assertSame([], $split['appendix']);
    }

    public function testRegistryAcceptedSuppressesToAppendix(): void
    {
        $clusters = [$this->cluster('Fa1', ['src/A.php', 'src/B.php'], 42)];
        $registry = [
            'Fa1' => new BaselineDisposition(
                findingId: 'Fa1',
                status: 'accepted',
                note: 'protocol twins',
                acceptedAt: '2026-11-20T10:00:00+00:00',
                gitSha: 'abc123',
                files: ['src/A.php', 'src/B.php'],
            ),
        ];

        $split = (new BaselineFilter())->split($clusters, $registry, [], []);

        $this->assertSame([], $split['main']);
        $this->assertCount(1, $split['appendix']);
        $row = $split['appendix'][0];
        $this->assertSame('Fa1', $row['finding_id']);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('registry', $row['source']);
        $this->assertFalse($row['resurfaced']);
        $this->assertSame('protocol twins', $row['note']);
        // The decision time from the file survives; the live accept
        // timestamp does NOT overwrite it.
        $this->assertSame('2026-11-20T10:00:00+00:00', $row['accepted_at']);
        $this->assertSame('abc123', $row['git_sha']);
        $this->assertSame(2, $row['members']);
        $this->assertSame(42, $row['impact']);
        $this->assertNotEmpty($row['member_hashes']);
    }

    public function testUnresolvedNeverSuppressesButIsNamed(): void
    {
        $clusters = [$this->cluster('Fu1', ['src/U.php'])];
        $registry = [
            'Fu1' => new BaselineDisposition(findingId: 'Fu1', status: 'unresolved', note: 'needs triage'),
        ];

        $split = (new BaselineFilter())->split($clusters, $registry, [], []);

        $this->assertCount(1, $split['main'], 'an unresolved entry must not hide the finding');
        $this->assertCount(1, $split['appendix']);
        $this->assertSame('unresolved', $split['appendix'][0]['status']);
        $this->assertSame('Fu1', $split['appendix'][0]['finding_id']);
        $this->assertSame('needs triage', $split['appendix'][0]['note']);
    }

    public function testDirectiveExactIdFileScoped(): void
    {
        $clusters = [$this->cluster('Fd1', ['src/D.php']), $this->cluster('Fd2', ['src/E.php'])];
        $directives = [$this->directive('src/D.php', 'Fd1', 'kept on purpose')];

        $split = (new BaselineFilter())->split($clusters, [], [], $directives);

        $this->assertSame(['Fd2'], array_map(static fn(Cluster $c): string => $c->id, $split['main']));
        $this->assertCount(1, $split['appendix']);
        $this->assertSame('directive', $split['appendix'][0]['source']);
        $this->assertSame('kept on purpose', $split['appendix'][0]['note']);
        $this->assertSame('src/D.php', $split['appendix'][0]['directive_file']);
    }

    public function testDirectiveCannotLaunderAcrossFiles(): void
    {
        // The directive names Fx, but it sits in a file the cluster does
        // NOT touch — an id must not be laundered across the tree.
        $clusters = [$this->cluster('Fx', ['src/Hold.php'])];
        $directives = [$this->directive('src/Elsewhere.php', 'Fx')];

        $split = (new BaselineFilter())->split($clusters, [], [], $directives);

        $this->assertCount(1, $split['main']);
        $this->assertSame([], $split['appendix']);
    }

    public function testWildcardDirectiveAcceptsFileMembers(): void
    {
        $clusters = [$this->cluster('Fw1', ['src/W.php']), $this->cluster('Fw2', ['src/V.php'])];
        $directives = [$this->directive('src/W.php', '*', 'this file mirrors intentionally')];

        $split = (new BaselineFilter())->split($clusters, [], [], $directives);

        $this->assertSame(['Fw2'], array_map(static fn(Cluster $c): string => $c->id, $split['main']));
        $row = $split['appendix'][0];
        $this->assertSame('Fw1', $row['finding_id']);
        $this->assertSame('directive', $row['source']);
        $this->assertSame('this file mirrors intentionally', $row['note']);
    }

    public function testConfigListAcceptedAndPrecedence(): void
    {
        $clusters = [$this->cluster('Fc1', ['src/C.php'])];
        $split = (new BaselineFilter())->split($clusters, [], ['Fc1'], []);

        $this->assertSame([], $split['main']);
        $this->assertSame('config', $split['appendix'][0]['source']);

        // Precedence: registry outranks config for the same id (source +
        // note come from the registry record).
        $registry = ['Fc1' => new BaselineDisposition(findingId: 'Fc1', status: 'accepted', note: 'from file')];
        $split2 = (new BaselineFilter())->split($clusters, $registry, ['Fc1'], []);
        $this->assertSame('registry', $split2['appendix'][0]['source']);
        $this->assertSame('from file', $split2['appendix'][0]['note']);
    }

    public function testResurfaceKeepsClusterInMainAndNamesRotation(): void
    {
        // The registry accepted an id that is no longer current (content
        // drifted); the live cluster shares its file provenance.
        $live = $this->cluster('Fnew', ['src/Drift.php']);
        $registry = [
            'Fold' => new BaselineDisposition(
                findingId: 'Fold',
                status: 'accepted',
                note: 'old decision',
                files: ['src/Drift.php'],
            ),
        ];

        $split = (new BaselineFilter())->split([$live], $registry, [], []);

        $this->assertCount(1, $split['main'], 'fail-open: the drifted finding stays visible');
        $this->assertSame('Fnew', $split['main'][0]->id);

        $this->assertCount(1, $split['appendix']);
        $row = $split['appendix'][0];
        $this->assertTrue($row['resurfaced']);
        $this->assertSame('Fnew', $row['finding_id'], 'the appendix names the CURRENT id');
        $this->assertSame('Fold', $row['accepted_id']);
        $this->assertSame('registry', $row['source']);
        $this->assertStringContainsString('src/Drift.php', implode(',', (array)$row['files']));
    }

    public function testAcceptedEntryWithoutProvenanceMatchIsAbsentRow(): void
    {
        $registry = [
            'Ggone' => new BaselineDisposition(
                findingId: 'Ggone',
                status: 'accepted',
                files: ['src/Deleted.php'],
            ),
        ];
        $clusters = [$this->cluster('Glive', ['src/Other.php'])];

        $split = (new BaselineFilter())->split($clusters, $registry, [], []);

        $this->assertCount(1, $split['main']);
        $this->assertCount(1, $split['appendix']);
        $row = $split['appendix'][0];
        $this->assertSame('Ggone', $row['finding_id']);
        $this->assertTrue($row['absent']);
        $this->assertFalse($row['resurfaced'], 'no live cluster joined — this is history, not a re-review flag');
    }

    public function testAppendixSortedByFindingId(): void
    {
        $clusters = [
            $this->cluster('Fzzz', ['src/Z.php']),
            $this->cluster('Faaa', ['src/A.php']),
        ];
        $split = (new BaselineFilter())->split($clusters, [], ['Fzzz', 'Faaa'], []);

        $this->assertSame(['Faaa', 'Fzzz'], array_map(static fn(array $r): string => $r['finding_id'], $split['appendix']));
    }

    public function testPersistableRowsSelectsOnlyLiveAcceptances(): void
    {
        $appendix = [
            ['finding_id' => 'Fr', 'status' => 'accepted', 'source' => 'registry', 'resurfaced' => false],
            ['finding_id' => 'Fc', 'status' => 'accepted', 'source' => 'config', 'resurfaced' => false],
            ['finding_id' => 'Fd', 'status' => 'accepted', 'source' => 'directive', 'resurfaced' => false],
            ['finding_id' => 'Fs', 'status' => 'accepted', 'source' => 'registry', 'resurfaced' => true, 'accepted_id' => 'Fold'],
            ['finding_id' => 'Fu', 'status' => 'unresolved', 'source' => 'registry', 'resurfaced' => false],
        ];

        $persistable = BaselineFilter::persistableRows($appendix);
        $this->assertSame(
            ['Fc', 'Fd'],
            array_map(static fn(array $r): string => $r['finding_id'], $persistable),
            'registry rows are already in the file; resurfaced rows must not silently re-accept drift; unresolved is not an acceptance',
        );
    }

    public function testPersistableRoundtripThroughDispositionRecord(): void
    {
        $clusters = [$this->cluster('Fp1', ['src/P.php'])];
        $split = (new BaselineFilter())->split($clusters, [], ['Fp1'], []);

        $rows = BaselineFilter::persistableRows($split['appendix']);
        $this->assertCount(1, $rows);

        $record = BaselineDisposition::fromAppendixRow($rows[0]);
        $this->assertSame('Fp1', $record->findingId);
        $this->assertTrue($record->isAccepted());
        $this->assertSame('accepted list in phpdup.json', $record->note);
        $this->assertNotEmpty($record->memberHashes);

        // The record writes a v4 file that a later run reads back.
        $tmp = sys_get_temp_dir() . '/phpdup-filter-roundtrip-' . uniqid() . '.json';
        $store = new BaselineStore();
        $store->writeBaseline($split['main'], $tmp, [$record->findingId => $record]);
        $this->assertSame(
            [$record->findingId => $record->toArray()],
            array_map(static fn(BaselineDisposition $d): array => $d->toArray(), $store->readDispositions($tmp)),
        );
        unlink($tmp);
    }
}
