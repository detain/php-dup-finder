<?php
declare(strict_types=1);

namespace Acme\Search\Analytics;

use Acme\Search\Domain\TermStat;

final class TopSearchTermLister
{
    /**
     * @param array<string, TermStat> $stats  term => TermStat
     * @return array<int, array{0:int,1:string}>
     */
    public function topTerms(array $stats): array
    {
        $rows = [];

        // identical token-shape: foreach key=>value, push tuple, sort desc by 0
        foreach ($stats as $term => $stat) {
            $rows[] = [$stat->hitCount(), $term . ' (' . $stat->locale() . ')'];
        }
        usort($rows, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return $rows;
    }

    /**
     * @param array<string, TermStat> $stats
     */
    public function topAsCsv(array $stats, int $limit): string
    {
        $top = array_slice($this->topTerms($stats), 0, $limit);
        return implode("\n", array_map(
            static fn (array $r): string => $r[0] . ',' . $r[1],
            $top,
        ));
    }
}
