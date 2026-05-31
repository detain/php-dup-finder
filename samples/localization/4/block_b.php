<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\AnnouncementRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class AnnouncementLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly AnnouncementRepository $announcementRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedAnnouncement(int $announcementId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildAnnouncementCacheKey($announcementId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'announcement', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'announcement', 'locale' => $locale]);

        $announcement = $this->announcementRepository->find($announcementId);

        if ($announcement === null) {
            return null;
        }

        $data = $this->translateAnnouncement($announcement, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getActiveAnnouncements(?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildActiveAnnouncementsCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $announcements = $this->announcementRepository->findActive();

        $results = [];
        foreach ($announcements as $announcement) {
            $results[] = $this->translateAnnouncement($announcement, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateAnnouncement(int $announcementId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildAnnouncementCacheKey($announcementId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildActiveAnnouncementsCacheKey($l));
        }

        $this->logger->debug('Invalidated announcement localization', [
            'announcement_id' => $announcementId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('announcement:*:' . $locale);

        $this->logger->info('Invalidated all announcements for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateAnnouncementTranslation(int $announcementId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildAnnouncementCacheKey($announcementId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'announcement',
            'announcement_id' => (string) $announcementId,
            'locale' => $locale,
        ]);
    }

    private function buildAnnouncementCacheKey(int $announcementId, string $locale): string
    {
        return "announcement:{$announcementId}:{$locale}";
    }

    private function buildActiveAnnouncementsCacheKey(string $locale): string
    {
        return "announcement:active:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateAnnouncement(object $announcement, string $locale): array
    {
        return [
            'id' => $announcement->getId(),
            'type' => $announcement->getType(),
            'title' => $this->translator->translate($announcement->getTitleKey(), $locale),
            'content' => $this->translator->translate($announcement->getContentKey(), $locale),
            'action_text' => $this->translator->translate($announcement->getActionTextKey(), $locale),
            'dismissible' => $announcement->isDismissible(),
            'starts_at' => $announcement->getStartsAt()?->format(\DATE_ATOM),
            'ends_at' => $announcement->getEndsAt()?->format(\DATE_ATOM),
            'locale' => $locale,
        ];
    }
}
