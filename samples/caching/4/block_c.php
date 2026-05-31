<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\ReportRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ReportCacheHandler
{
    private const CACHE_PREFIX = 'report';
    private const DEFAULT_TTL = 3600;
    private const STALE_TTL = 1800;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly ReportRepository $reportRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getReport(int $reportId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildReportCacheKey($reportId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'report']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'report']);

        $report = $this->reportRepository->find($reportId);

        if ($report === null) {
            return null;
        }

        $data = $this->serializeReport($report);
        $this->setReport($reportId, $data);

        return $data;
    }

    public function setReport(int $reportId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildReportCacheKey($reportId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached report', [
            'report_id' => $reportId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateReport(int $reportId): void
    {
        $cacheKey = $this->buildReportCacheKey($reportId);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated report cache', [
            'report_id' => $reportId,
        ]);
    }

    public function refreshReport(int $reportId): void
    {
        $report = $this->reportRepository->find($reportId);

        if ($report === null) {
            $this->cache->delete($this->buildReportCacheKey($reportId));
            return;
        }

        $data = $this->serializeReport($report);
        $this->setReport($reportId, $data);

        $this->logger->debug('Refreshed report cache', [
            'report_id' => $reportId,
        ]);
    }

    public function warmReport(int $reportId): void
    {
        $report = $this->reportRepository->find($reportId);

        if ($report !== null) {
            $data = $this->serializeReport($report);
            $this->setReport($reportId, $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed report cache', [
            'report_id' => $reportId,
        ]);
    }

    public function getReportData(int $reportId, array $filters, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildReportDataCacheKey($reportId, $filters);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'report_data']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'report_data']);

        $data = $this->reportRepository->getReportData($reportId, $filters);

        if ($data === null) {
            return null;
        }

        $this->setReportData($reportId, $filters, $data);

        return $data;
    }

    public function setReportData(int $reportId, array $filters, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildReportDataCacheKey($reportId, $filters);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached report data', [
            'report_id' => $reportId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateReportData(int $reportId, array $filters): void
    {
        $cacheKey = $this->buildReportDataCacheKey($reportId, $filters);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated report data cache', [
            'report_id' => $reportId,
        ]);
    }

    public function refreshReportData(int $reportId, array $filters): void
    {
        $data = $this->reportRepository->getReportData($reportId, $filters);

        if ($data === null) {
            $this->cache->delete($this->buildReportDataCacheKey($reportId, $filters));
            return;
        }

        $this->setReportData($reportId, $filters, $data);

        $this->logger->debug('Refreshed report data cache', [
            'report_id' => $reportId,
        ]);
    }

    public function getScheduledReport(int $scheduleId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildScheduledReportCacheKey($scheduleId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'scheduled_report']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'scheduled_report']);

        $schedule = $this->reportRepository->findSchedule($scheduleId);

        if ($schedule === null) {
            return null;
        }

        $data = $this->serializeScheduledReport($schedule);
        $this->setScheduledReport($scheduleId, $data);

        return $data;
    }

    public function setScheduledReport(int $scheduleId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildScheduledReportCacheKey($scheduleId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached scheduled report', [
            'schedule_id' => $scheduleId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateScheduledReport(int $scheduleId): void
    {
        $cacheKey = $this->buildScheduledReportCacheKey($scheduleId);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated scheduled report cache', [
            'schedule_id' => $scheduleId,
        ]);
    }

    public function refreshScheduledReport(int $scheduleId): void
    {
        $schedule = $this->reportRepository->findSchedule($scheduleId);

        if ($schedule === null) {
            $this->cache->delete($this->buildScheduledReportCacheKey($scheduleId));
            return;
        }

        $data = $this->serializeScheduledReport($schedule);
        $this->setScheduledReport($scheduleId, $data);

        $this->logger->debug('Refreshed scheduled report cache', [
            'schedule_id' => $scheduleId,
        ]);
    }

    public function getReportExport(int $reportId, string $format, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildReportExportCacheKey($reportId, $format);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'report_export']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'report_export']);

        $export = $this->reportRepository->findExport($reportId, $format);

        if ($export === null) {
            return null;
        }

        $data = $this->serializeReportExport($export);
        $this->setReportExport($reportId, $format, $data);

        return $data;
    }

    public function setReportExport(int $reportId, string $format, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildReportExportCacheKey($reportId, $format);
        $ttl = $ttl ?? 1800;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached report export', [
            'report_id' => $reportId,
            'format' => $format,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateReportExport(int $reportId, string $format): void
    {
        $cacheKey = $this->buildReportExportCacheKey($reportId, $format);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated report export cache', [
            'report_id' => $reportId,
            'format' => $format,
        ]);
    }

    public function handleReportUpdate(int $reportId): void
    {
        $this->invalidateReport($reportId);

        $filterSets = $this->reportRepository->findFilterSetsForReport($reportId);
        foreach ($filterSets as $filters) {
            $this->invalidateReportData($reportId, $filters);
        }

        $formats = ['pdf', 'csv', 'excel'];
        foreach ($formats as $format) {
            $this->invalidateReportExport($reportId, $format);
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'report_update',
            'report_id' => (string) $reportId,
        ]);

        $this->logger->info('Handled report update cache invalidation', [
            'report_id' => $reportId,
        ]);
    }

    public function handleScheduleChange(int $scheduleId): void
    {
        $this->invalidateScheduledReport($scheduleId);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'schedule_change',
            'schedule_id' => (string) $scheduleId,
        ]);

        $this->logger->info('Handled schedule change cache invalidation', [
            'schedule_id' => $scheduleId,
        ]);
    }

    public function handleGlobalReportUpdate(): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, '*');
        $this->cache->deleteByPattern($pattern);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'global_report_update',
        ]);

        $this->logger->info('Handled global report update cache invalidation');
    }

    private function buildReportCacheKey(int $reportId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'report', (string) $reportId);
    }

    private function buildReportDataCacheKey(int $reportId, array $filters): string
    {
        ksort($filters);
        return $this->keyBuilder->build(
            self::CACHE_PREFIX,
            'report',
            (string) $reportId,
            'data',
            md5(json_encode($filters))
        );
    }

    private function buildScheduledReportCacheKey(int $scheduleId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'schedule', (string) $scheduleId);
    }

    private function buildReportExportCacheKey(int $reportId, string $format): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'report', (string) $reportId, 'export', $format);
    }

    private function serializeReport(object $report): array
    {
        return [
            'id' => $report->getId(),
            'name' => $report->getName(),
            'type' => $report->getType(),
            'definition' => $report->getDefinition(),
        ];
    }

    private function serializeScheduledReport(object $schedule): array
    {
        return [
            'id' => $schedule->getId(),
            'report_id' => $schedule->getReportId(),
            'cron_expression' => $schedule->getCronExpression(),
            'recipients' => $schedule->getRecipients(),
        ];
    }

    private function serializeReportExport(object $export): array
    {
        return [
            'path' => $export->getPath(),
            'size' => $export->getSize(),
            'generated_at' => $export->getGeneratedAt()?->format(\DATE_ATOM),
        ];
    }
}
