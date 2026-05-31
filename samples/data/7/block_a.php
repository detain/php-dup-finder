<?php
declare(strict_types=1);

namespace App\Commands\Reports;

use App\Bus\CommandHandlerInterface;
use App\Reporting\ReportRenderer;
use App\Database\Connection;
use Psr\Log\LoggerInterface;

final class GenerateMonthlyReportHandler implements CommandHandlerInterface
{
    public function __construct(
        private ReportRenderer $renderer,
        private Connection $db,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(object $command): array
    {
        $reportId = (int)$command->reportId;
        $startedAt = microtime(true);

        $report = $this->db->fetchOne(
            'SELECT id, period_start, period_end, account_id, status FROM monthly_reports WHERE id = ?',
            [$reportId]
        );

        if ($report === null) {
            throw new \RuntimeException("Report not found: {$reportId}");
        }

        if ($report['status'] === 'running') {
            throw new \DomainException('Report already running');
        }

        $this->db->execute(
            'UPDATE monthly_reports SET status = ?, started_at = NOW() WHERE id = ?',
            ['running', $reportId]
        );

        set_time_limit(30);

        try {
            $rendered = $this->renderer->render(
                (int)$report['account_id'],
                (string)$report['period_start'],
                (string)$report['period_end'],
            );

            $elapsed = microtime(true) - $startedAt;
            if ($elapsed > 30) {
                $this->logger->warning('Report exceeded soft timeout', [
                    'report_id' => $reportId,
                    'elapsed'   => $elapsed,
                ]);
            }

            $this->db->execute(
                'UPDATE monthly_reports SET status = ?, finished_at = NOW(), output_path = ? WHERE id = ?',
                ['completed', $rendered['path'], $reportId]
            );

            return ['ok' => true, 'path' => $rendered['path']];
        } catch (\Throwable $e) {
            $this->db->execute(
                'UPDATE monthly_reports SET status = ?, error = ? WHERE id = ?',
                ['failed', substr($e->getMessage(), 0, 1000), $reportId]
            );
            throw $e;
        }
    }
}
