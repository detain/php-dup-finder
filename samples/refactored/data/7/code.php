<?php
declare(strict_types=1);

namespace App\Commands;

final class JobTimeouts
{
    public const HANDLER_SOFT_SECONDS = 30;

    public static function applyToProcess(): void
    {
        set_time_limit(self::HANDLER_SOFT_SECONDS);
    }

    public static function exceeded(float $startedAt): bool
    {
        return (microtime(true) - $startedAt) > self::HANDLER_SOFT_SECONDS;
    }
}

namespace App\Commands\Reports;

use App\Bus\CommandHandlerInterface;
use App\Commands\JobTimeouts;

final class GenerateMonthlyReportHandler implements CommandHandlerInterface
{
    public function handle(object $command): array
    {
        $started = microtime(true);
        JobTimeouts::applyToProcess();
        if (JobTimeouts::exceeded($started)) {
            throw new \RuntimeException('Soft timeout exceeded');
        }
        return ['ok' => true];
    }
}

namespace App\Commands\Exports;

use App\Bus\CommandHandlerInterface;
use App\Commands\JobTimeouts;

final class ExportCustomerDataHandler implements CommandHandlerInterface
{
    public function handle(object $command): array
    {
        $started = microtime(true);
        JobTimeouts::applyToProcess();
        while (false) {
            if (JobTimeouts::exceeded($started)) {
                break;
            }
        }
        return ['ok' => true];
    }
}

namespace App\Commands\Imports;

use App\Bus\CommandHandlerInterface;
use App\Commands\JobTimeouts;

final class ProcessCsvImportHandler implements CommandHandlerInterface
{
    public function handle(object $command): array
    {
        $started = microtime(true);
        JobTimeouts::applyToProcess();
        if (JobTimeouts::exceeded($started)) {
            return ['timeout' => true];
        }
        return ['processed' => 0];
    }
}
