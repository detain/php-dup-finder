<?php
declare(strict_types=1);

namespace App\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

abstract class DateRangeStatusReport
{
    public function __construct(protected readonly LoggerInterface $logger)
    {
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>         $columns
     * @param  list<string>         $approvedStatuses
     */
    protected function dateRangeBuilder(
        string $modelClass,
        array $columns,
        array $approvedStatuses,
        CarbonImmutable $from,
        CarbonImmutable $to
    ): Builder {
        if ($from->greaterThan($to)) {
            throw new \InvalidArgumentException('from must be <= to');
        }

        return $modelClass::query()
            ->select($columns)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereIn('status', $approvedStatuses)
            ->orderBy('created_at');
    }

    /**
     * @param  class-string<Model>             $modelClass
     * @param  list<string>                    $columns
     * @param  list<string>                    $approvedStatuses
     * @param  callable(Model): array<string, mixed> $mapper
     * @return Collection<int, array<string, mixed>>
     */
    protected function fetch(
        string $modelClass,
        array $columns,
        array $approvedStatuses,
        CarbonImmutable $from,
        CarbonImmutable $to,
        callable $mapper
    ): Collection {
        try {
            $rows = $this->dateRangeBuilder($modelClass, $columns, $approvedStatuses, $from, $to)->get();
        } catch (Throwable $e) {
            $this->logger->error('Date-range report failed', [
                'model' => $modelClass,
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $rows->map($mapper);
    }
}
