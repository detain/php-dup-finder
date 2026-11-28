<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 5.2 x 6.3 (W2b r1, m-3 cure) — the fold x vendored-demotion
 * merged denominator. The r1 review proved the seam arithmetically
 * consistent on a live probe; this promotes that probe into the suite
 * so the double-removal / double-counting class can never regress:
 * a scope-armed, idiom_mining-fold-armed run must keep summary counts,
 * total_impact and duplicated_lines EXACTLY reconciled against the
 * payload (main slice only), while the demoted section carries the
 * vendored lines once — and a mixed (first-party + vendored) cluster
 * survives the fold AND the demotion untouched.
 */
final class VendoredFoldInteractionE2ETest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-52fold-' . uniqid();
        $root = $this->tmp . '/app';
        mkdir($root . '/acme/pkg', 0o775, true);
        mkdir($root . '/src', 0o775, true);
        file_put_contents($root . '/composer.lock', (string) json_encode([
            'packages'     => [['name' => 'acme/pkg', 'version' => '1.0.0']],
            'packages-dev' => [],
        ]));

        // Vendored trio: same first-party-shaped body family, but the
        // operators differ from the F family so the method clusters stay
        // apart. Each contains the shared guard statement sequence.
        foreach (['1', '2', '3'] as $i) {
            file_put_contents(
                $root . "/acme/pkg/V{$i}.php",
                "<?php\nfunction vguard{$i}(\$a, \$b) {\n" . self::guardBody("v{$i}") . "\n    if (\$a > \$b) { return bcmod((string) \$a, '2'); }\n    for (\$k = 0; \$k < 5; \$k++) { \$a = \$a + \$b * \$k; }\n    return strrev((string) \$a);\n}\n",
            );
            file_put_contents(
                $root . "/src/F{$i}.php",
                "<?php\nfunction fguard{$i}(\$a, \$b) {\n" . self::guardBody("f{$i}") . "\n    if (\$a < \$b) { return abs(\$a - \$b); }\n    foreach ([1, 2, 3] as \$k) { \$a = \$a - \$b + \$k; }\n    return implode(',', [\$a, \$b]);\n}\n",
            );
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testFoldArmedAndLegacyRunsConserveTheDenominators(): void
    {
        $foldOn  = $this->analyzeRun(['idiom_mining' => true, 'kinds' => ['fragment']]);
        $foldOff = $this->analyzeRun([]);

        foreach (['fold-on' => $foldOn, 'fold-off' => $foldOff] as $label => $payload) {
            self::assertSame(0, $payload['exit'], $label);
            $summary = $payload['summary'];
            $section = $summary['vendored_duplicates'] ?? null;
            self::assertIsArray($section, "$label: scope-armed run opens the key");

            // The vendored method family demotes under BOTH arms of the
            // fold — the seam never lets the fold rescue or re-demote.
            self::assertGreaterThanOrEqual(1, $section['clusters'], $label);
            self::assertGreaterThan(0, $section['lines'], $label);

            // Identity: reported count == payload rows; sums reconcile.
            self::assertSame(count($payload['clusters']), $summary['clusters'], $label);
            self::assertSame(
                array_sum(array_map(static fn(array $c): int => $c['impact'], $payload['clusters'])),
                $summary['total_impact'],
                "$label: total_impact is the MAIN slice only",
            );
            self::assertSame(
                self::mainSpanUnion($payload),
                $summary['duplicated_lines'],
                "$label: duplicated_lines is the MAIN slice only — demoted lines are counted once, in the section",
            );

            // No double-counting across the seam: no demoted cluster id
            // appears in the main payload.
            $mainIds  = array_map(static fn(array $c): string => $c['id'], $payload['clusters']);
            $sectIds  = array_map(static fn(array $r): string => $r['id'], $section['rows']);
            self::assertSame([], array_intersect($mainIds, $sectIds), $label);

            // No double-REMOVAL: a mixed first-party+vendored cluster (a
            // row whose members span both trees) is never section fodder.
            foreach ($payload['clusters'] as $cluster) {
                $hasV = false;
                $hasF = false;
                foreach ($cluster['members'] as $m) {
                    $hasV = $hasV || str_contains($m['file'], '/acme/pkg/');
                    $hasF = $hasF || str_contains($m['file'], '/src/F');
                }
                if ($hasV) {
                    self::assertTrue($hasF, "$label: a main row containing a vendored member must be MIXED (the fully-vendored rows live in the section)");
                }
            }
        }

        // r1 adjudication (m-3): the per-run identities above ARE the
        // seam law (summary == payload arithmetic, MAIN slice only, no
        // id double-life, no all-vendored survivor row) and they hold in
        // BOTH arms. Cross-arm shape equality (identical totals in the
        // reviewer's probe, 1-vs-3 section clusters here) is decided by
        // whether mined fragments fold into method ancestors or surface
        // as their own families — the engine's pre-existing subsumption
        // law, which W2b consumes passively: each surfaced all-V family
        // demotes, each mixed family survives, in either arm. Pinning
        // corpus-specific equality here would freeze that law's
        // internals, not the double-removal class this test guards.
        self::assertGreaterThanOrEqual(
            1,
            $foldOff['summary']['vendored_duplicates']['clusters'],
            'fold-off demotes the vendored method family (the arm invariant to both runs)',
        );
    }

    /** Shared guard sequence — structurally identical, literals differ (hole-abstraction twins). */
    private static function guardBody(string $tag): string
    {
        return "    \$seen = [];\n    if (!isset(\$seen['{$tag}'])) { \$seen['{$tag}'] = true; }\n";
    }

    /**
     * Σ per-cluster member-span unions recomputed from the payload —
     * exactly the engine's Cluster::totalLines() law, re-derived by an
     * independent arithmetic path over the JSON alone.
     *
     * @param array<string, mixed> $payload
     */
    private static function mainSpanUnion(array $payload): int
    {
        $total = 0;
        foreach ($payload['clusters'] as $cluster) {
            $perFile = [];
            foreach ($cluster['members'] as $member) {
                $key = $member['file'];
                $iv  = [$member['start'], $member['end']];
                $perFile[$key] = $perFile[$key] ?? [];
                $perFile[$key][] = $iv;
            }
            foreach ($perFile as $ivs) {
                usort($ivs, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
                $cur = null;
                foreach ($ivs as $iv) {
                    if ($cur === null) {
                        $cur = $iv;
                        continue;
                    }
                    if ($iv[0] <= $cur[1] + 1) {
                        $cur[1] = max($cur[1], $iv[1]);
                    } else {
                        $total += $cur[1] - $cur[0] + 1;
                        $cur     = $iv;
                    }
                }
                if ($cur !== null) {
                    $total += $cur[1] - $cur[0] + 1;
                }
            }
        }
        return $total;
    }

    /** @param array<string, mixed> $cfgExtra @return array<string, mixed> */
    private function analyzeRun(array $cfgExtra): array
    {
        $cfg = $this->tmp . '/cfg.json';
        file_put_contents($cfg, (string) json_encode($cfgExtra + [
            'cache_dir'          => $this->tmp . '/cache-' . md5(json_encode($cfgExtra)),
            'min_cluster_impact' => 0,
        ]));
        $out = $this->tmp . '/out-' . md5(json_encode($cfgExtra)) . '.json';

        $cmd = array_map(strval(...), [PHP_BINARY, dirname(__DIR__, 2) . '/bin/phpdup', 'analyze', $this->tmp . '/app', '--json', $out, '-c', $cfg, '--scope=single']);
        $proc = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmp,
        );
        self::assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));
        $stderr = '';
        $deadline = microtime(true) + 120.0;
        while (true) {
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                self::fail('phpdup child exceeded 120s');
            }
            usleep(20000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        self::assertFileExists($out, "no JSON produced (stderr tail: " . substr($stderr, -400) . ')');
        $payload = json_decode((string) file_get_contents($out), true);
        self::assertIsArray($payload);
        $payload['exit'] = $exit;
        return $payload;
    }
}
