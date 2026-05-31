<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\ReportRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ReportLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'pl', 'ru', 'uk', 'tr'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly ReportRepository $reportRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedReport(int $reportId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildReportCacheKey($reportId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'report', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'report', 'locale' => $locale]);

        $report = $this->reportRepository->find($reportId);

        if ($report === null) {
            return null;
        }

        $data = $this->translateReport($report, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getReportSections(int $reportId, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildSectionsCacheKey($reportId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $sections = $this->reportRepository->findSectionsByReportId($reportId);

        $results = [];
        foreach ($sections as $section) {
            $results[] = $this->translateSection($section, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateReport(int $reportId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildReportCacheKey($reportId, $l);
            $this->translator->invalidateCache($cacheKey);
            $this->translator->invalidateCache($this->buildSectionsCacheKey($reportId, $l));
        }

        $this->logger->debug('Invalidated report localization', [
            'report_id' => $reportId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('report:*:' . $locale);

        $this->logger->info('Invalidated all reports for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateReportTranslation(int $reportId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildReportCacheKey($reportId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'report',
            'report_id' => (string) $reportId,
            'locale' => $locale,
        ]);
    }

    private function buildReportCacheKey(int $reportId, string $locale): string
    {
        return "report:{$reportId}:{$locale}";
    }

    private function buildSectionsCacheKey(int $reportId, string $locale): string
    {
        return "report:{$reportId}:sections:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateReport(object $report, string $locale): array
    {
        return [
            'id' => $report->getId(),
            'type' => $report->getType(),
            'title' => $this->translator->translate($report->getTitleKey(), $locale),
            'summary' => $this->translator->translate($report->getSummaryKey(), $locale),
            'generated_at' => $report->getGeneratedAt()?->format(\DATE_ATOM),
            'locale' => $locale,
        ];
    }

    private function translateSection(object $section, string $locale): array
    {
        return [
            'id' => $section->getId(),
            'report_id' => $section->getReportId(),
            'title' => $this->translator->translate($section->getTitleKey(), $locale),
            'content' => $this->translator->translate($section->getContentKey(), $locale),
            'order' => $section->getOrder(),
            'locale' => $locale,
        ];
    }
}
