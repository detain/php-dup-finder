<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders the report to a terminal in the format described in
 * ARCHITECTURE.md §9. Compact when --no-ansi; richly colored when the
 * output supports it.
 */
final class CliReporter
{
    public function render(Report $report, OutputInterface $out, int $limit = 50): void
    {
        $clusters = $report->clusters;
        if (!$clusters) {
            $out->writeln('');
            $out->writeln('<info>phpdup</info> no clusters above threshold.');
            return;
        }
        $shown = min($limit, count($clusters));
        $out->writeln('');
        $out->writeln(sprintf(
            '<comment>%d cluster(s); showing top %d (sorted by impact)</comment>',
            count($clusters), $shown
        ));
        for ($i = 0; $i < $shown; $i++) {
            $this->renderCluster($i + 1, $clusters[$i], $out);
        }
        $out->writeln('');
        $out->writeln(sprintf(
            '<info>summary</info> %d clusters · %d duplicated lines · %d total impact',
            count($clusters),
            $report->totalDuplicatedLines(),
            array_sum(array_map(fn(Cluster $c) => $c->impact, $clusters)),
        ));
    }

    private function renderCluster(int $num, Cluster $c, OutputInterface $out): void
    {
        $out->writeln('');
        $out->writeln('<fg=cyan>═════════════════════════════════════════════════════════════</>');
        $out->writeln(sprintf(
            '<fg=cyan>  Cluster #%d   similarity %.2f   impact %d   members %d%s</>',
            $num, $c->similarity, $c->impact, $c->size(),
            $c->exact ? '   <fg=green>EXACT</>' : ''
        ));
        $out->writeln('<fg=cyan>─────────────────────────────────────────────────────────────</>');

        foreach ($c->members as $m) {
            $out->writeln(sprintf(
                '  %s   <comment>%s</comment>',
                $m->location(), $m->qualifiedName()
            ));
        }

        if ($c->signature !== null) {
            $out->writeln('');
            $out->writeln('  <fg=yellow>Suggested abstraction:</>');
            foreach (preg_split("/\r?\n/", $c->signature) ?: [] as $line) {
                $out->writeln('    ' . $line);
            }
        }

        if ($c->holes) {
            $out->writeln('');
            $out->writeln('  <fg=yellow>Holes:</>');
            foreach ($c->holes as $h) {
                $observed = $this->summarizeObserved($h->observedValues);
                $out->writeln(sprintf(
                    '    %-12s %-12s observed: %s',
                    $h->suggestedName,
                    $h->inferredType,
                    $observed
                ));
            }
        }

        if ($c->patternTags) {
            $out->writeln('');
            $out->writeln('  <fg=magenta>Pattern: ' . implode(', ', $c->patternTags) . '</>');
        }

        $out->writeln(sprintf('  Confidence: %.2f', $c->confidence));
        $out->writeln('<fg=cyan>═════════════════════════════════════════════════════════════</>');
    }

    /** @param list<string> $values */
    private function summarizeObserved(array $values): string
    {
        $unique = array_values(array_unique($values));
        $shown = array_slice($unique, 0, 5);
        $rendered = array_map(fn($v) => strlen($v) > 40 ? substr($v, 0, 37) . '...' : $v, $shown);
        $extra = count($unique) > 5 ? sprintf(' (+%d more)', count($unique) - 5) : '';
        return implode(', ', $rendered) . $extra;
    }
}
