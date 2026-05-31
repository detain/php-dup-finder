<?php

declare(strict_types=1);

namespace Acme\Billing\Reports;

use Acme\Common\Money;
use Psr\Log\LoggerInterface;

/**
 * @template T of object
 */
final class TabularReportBuilder
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $reportName,
    ) {
    }

    /**
     * @param iterable<T> $records
     * @param callable(T): array<string, mixed> $rowMapper
     * @param array<string, callable(T): int> $accumulators  // metric name -> int-cents extractor
     * @param callable(array<string, int>): array<string, mixed> $totalsFormatter
     * @param array<string, mixed> $logContext
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(
        iterable $records,
        callable $rowMapper,
        array $accumulators,
        callable $totalsFormatter,
        array $logContext,
    ): array {
        $rows = [];
        $sums = array_fill_keys(array_keys($accumulators), 0);
        $count = 0;

        foreach ($records as $record) {
            $rows[] = $rowMapper($record);
            foreach ($accumulators as $name => $extractor) {
                $sums[$name] += $extractor($record);
            }
            $count++;
        }

        $totals = $totalsFormatter($sums) + ['count' => $count];
        $this->logger->info("{$this->reportName} generated", $logContext + ['count' => $count]);

        return ['rows' => $rows, 'totals' => $totals];
    }
}
