<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\Node\Stmt\Nop;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Refactor\FunctionNameRegistry;
use Phpdup\Util\LineRange;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * W4-B fix M3/M4: one shared `namespace Refactored;`, many clusters — the
 * registry must make every emitted function name unique per report payload,
 * deterministically, from content ids (never list order), while leaving the
 * first claimer untouched. The ReportStage wiring test proves the pass is
 * LIVE (deleting the resolve() call turns it red).
 */
final class FunctionNameRegistryTest extends TestCase
{
    public function testFirstClaimWinsAndTheLoserGainsItsContentIdSuffix(): void
    {
        $a = $this->cluster('FAAAAA11112222', 'sharedThing');
        $b = $this->cluster('FBBBBB33334444', 'sharedThing');

        $renamed = (new FunctionNameRegistry())->resolve([$a, $b]);

        $this->assertSame(1, $renamed);
        $this->assertStringStartsWith('function sharedThing(', (string) $a->signature);
        $this->assertStringStartsWith('function sharedThing_FBBBBB33(', (string) $b->signature);
    }

    public function testNamesFollowContentIdsNotListPosition(): void
    {
        $a = $this->cluster('FAAAAA11112222', 'sharedThing');
        $b = $this->cluster('FBBBBB33334444', 'sharedThing');
        (new FunctionNameRegistry())->resolve([$b, $a]); // reversed arrival

        // First in LIST order claims verbatim; the suffix still names the
        // loser's own id — reproducible across runs, index-free.
        $this->assertStringStartsWith('function sharedThing(', (string) $b->signature);
        $this->assertStringStartsWith('function sharedThing_FAAAAA11(', (string) $a->signature);
    }

    public function testSuffixGrowsWhenTheShortTierIsAlreadyClaimed(): void
    {
        // c0 verbatim-mines `x_IDEM1111`; c1 claims `x`; c2 (whose id starts
        // IDEM1111) collides on `x` — its 8-char tier would spell exactly
        // c0's claimed name, so the tier must grow to 16 to stay unique.
        $first  = $this->cluster('F11111111AAAAAAAA', 'x_IDEM1111');
        $second = $this->cluster('F22222222BBBBBBBB', 'x');
        $third  = $this->cluster('IDEM111122223333', 'x');

        $renamed = (new FunctionNameRegistry())->resolve([$first, $second, $third]);

        $this->assertSame(1, $renamed);
        $this->assertStringStartsWith('function x_IDEM1111(', (string) $first->signature);
        $this->assertStringStartsWith('function x(', (string) $second->signature);
        $this->assertStringStartsWith('function x_IDEM111122223333(', (string) $third->signature);
    }

    public function testNullAndUnparseableSignaturesPassThroughUntouched(): void
    {
        $nullSig   = $this->cluster('FNULL00000000000');
        $nullSig->signature = null;
        $weird                = $this->cluster('FWEIRD0000000000');
        $weird->signature     = 'not a signature at all';

        $renamed = (new FunctionNameRegistry())->resolve([$nullSig, $weird, $weird]);

        $this->assertSame(0, $renamed, 'unparseable duplicates are left to the consumers own fallbacks');
        $this->assertNull($nullSig->signature);
        $this->assertSame('not a signature at all', $weird->signature);
    }

    public function testResolveIsIdempotentWithinOneInstance(): void
    {
        $a     = $this->cluster('FAAAAA11112222', 'sharedThing');
        $b     = $this->cluster('FBBBBB33334444', 'sharedThing');
        $reg   = new FunctionNameRegistry();
        $reg->resolve([$a, $b]);
        $first = [(string) $a->signature, (string) $b->signature];

        $this->assertSame(0, $reg->resolve([$a, $b]));
        $this->assertSame($first, [(string) $a->signature, (string) $b->signature]);
    }

    public function testFreshInstanceCarriesNoClaimsFromEarlierPayloads(): void
    {
        // The lifecycle law made concrete: a second run() (Watch/Server
        // cycles) must resolve from a blank slate, not inherit claims.
        $a1 = $this->cluster('FCCCCC55556666', 'dupName');
        $b1 = $this->cluster('FDDDDD77778888', 'dupName');
        (new FunctionNameRegistry())->resolve([$a1, $b1]);

        $a2 = $this->cluster('FCCCCC55556666', 'dupName');
        $b2 = $this->cluster('FDDDDD77778888', 'dupName');
        (new FunctionNameRegistry())->resolve([$a2, $b2]);

        $this->assertSame($a1->signature, $a2->signature);
        $this->assertSame($b1->signature, $b2->signature);
    }

    public function testNameOfAndWithNameRoundTrip(): void
    {
        $sig  = "function notifyMailer(\n    mixed \$x,\n): mixed";
        $name = FunctionNameRegistry::nameOf($sig);
        $this->assertSame('notifyMailer', $name);

        $swapped = FunctionNameRegistry::withName($sig, 'notifyMailer_ZZZ9');
        $this->assertSame('notifyMailer_ZZZ9', FunctionNameRegistry::nameOf($swapped));
        $this->assertStringContainsString('mixed $x', $swapped, 'params survive verbatim');
    }

    public function testReportStageActuallyRunsTheResolver(): void
    {
        // Wiring proof: with two clusters that arrive at ReportStage sharing
        // one mined name, the emitted report payload must show unique names.
        // Delete the resolve() call in ReportStage and this goes red.
        $tmp     = (string) sys_get_temp_dir() . '/phpdup-fnr-' . uniqid('', true);
        $cache   = $tmp . '/cache';
        $json    = $tmp . '/report.json';
        mkdir($cache, 0777, true);
        try {
            $config = new Config(
                paths: [$tmp], exclude: [], cacheDir: $cache, jsonReportFile: $json,
                // hand-built clusters have tiny ranges; keep them past the Ranker floor
                minClusterImpact: 1,
            );
            $state = new PipelineState($config);
            $state->files    = ['seeded.php'];
            $state->clusters = [
                $this->cluster('FRRRRR11112222', 'batchResolve', impact: 9),
                $this->cluster('FSSSSS33334444', 'batchResolve', impact: 9),
            ];

            (new ReportStage(limit: 50, showStats: false))->run($state, new BufferedOutput());

            $this->assertNotNull($state->report);
            $decoded = json_decode((string) file_get_contents($json), true);
            $names   = array_map(
                static fn(array $c): ?string => FunctionNameRegistry::nameOf((string) $c['signature']),
                $decoded['clusters'],
            );
            $this->assertCount(2, $names);
            $this->assertSame(['batchResolve', 'batchResolve_FSSSSS33'], $names);
        } finally {
            @unlink($json);
            @array_map('unlink', glob($cache . '/*') ?: []);
            @rmdir($cache);
            @rmdir($tmp);
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function cluster(string $id, string $name = 'thing', int $impact = 4): Cluster
    {
        $cluster        = new Cluster($id, [$this->blk('a.php'), $this->blk('b.php')], 0.9, false);
        $cluster->signature = "function {$name}(\n    mixed \$v,\n): mixed";
        $cluster->impact      = $impact;
        return $cluster;
    }

    private function blk(string $file): Block
    {
        $block      = new Block($file, new LineRange(1, 3), 'method', 'Acme\\X', 'Host', 'run', new Nop());
        $block->size = 30; // Ranker recomputes impact from member sizes
        return $block;
    }
}
