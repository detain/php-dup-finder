<?php
declare(strict_types=1);

namespace App\Queue\Reports;

final class ReportProducer
{
    public function __construct(private \Redis $redis) {}

    public function enqueue(int $reportId, string $template, array $filters): void
    {
        $job = ['reportId' => $reportId, 'template' => $template, 'filters' => $filters, 'attempts' => 0];
        $this->redis->lPush('queue:report', json_encode($job));
    }
}

final class ReportWorker
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private \Redis $redis, private \Psr\Log\LoggerInterface $log) {}

    public function consume(): bool
    {
        $raw = $this->redis->brPop(['queue:report'], 5);
        if (!$raw) {
            return false;
        }
        $job = json_decode($raw[1], true);
        try {
            $this->process($job);
            $this->log->info('report rendered', ['id' => $job['reportId']]);
        } catch (\Throwable $e) {
            $job['attempts'] = ($job['attempts'] ?? 0) + 1;
            $job['lastError'] = $e->getMessage();
            $target = $job['attempts'] >= self::MAX_ATTEMPTS ? 'dlq:report' : 'queue:report';
            $this->redis->lPush($target, json_encode($job));
            $this->log->warning('report failed', ['attempt' => $job['attempts'], 'target' => $target]);
        }
        return true;
    }

    private function process(array $job): void
    {
        // render PDF
        file_put_contents("/tmp/report-{$job['reportId']}.pdf", $job['template']);
    }
}
