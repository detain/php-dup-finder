<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\EventRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class EventLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'ru'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly EventRepository $eventRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedEvent(int $eventId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildEventCacheKey($eventId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'event', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'event', 'locale' => $locale]);

        $event = $this->eventRepository->find($eventId);

        if ($event === null) {
            return null;
        }

        $data = $this->translateEvent($event, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getUpcomingEvents(?string $locale = null, int $limit = 10): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildUpcomingEventsCacheKey($locale, $limit);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $events = $this->eventRepository->findUpcoming($limit);

        $results = [];
        foreach ($events as $event) {
            $results[] = $this->translateEvent($event, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateEvent(int $eventId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildEventCacheKey($eventId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildUpcomingEventsCacheKey($l, 10));
        }

        $this->logger->debug('Invalidated event localization', [
            'event_id' => $eventId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('event:*:' . $locale);

        $this->logger->info('Invalidated all events for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateEventTranslation(int $eventId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildEventCacheKey($eventId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'event',
            'event_id' => (string) $eventId,
            'locale' => $locale,
        ]);
    }

    private function buildEventCacheKey(int $eventId, string $locale): string
    {
        return "event:{$eventId}:{$locale}";
    }

    private function buildUpcomingEventsCacheKey(string $locale, int $limit): string
    {
        return "event:upcoming:{$locale}:{$limit}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateEvent(object $event, string $locale): array
    {
        return [
            'id' => $event->getId(),
            'title' => $this->translator->translate($event->getTitleKey(), $locale),
            'description' => $this->translator->translate($event->getDescriptionKey(), $locale),
            'location' => $this->translator->translate($event->getLocationKey(), $locale),
            'organizer' => $event->getOrganizer(),
            'starts_at' => $event->getStartsAt()?->format(\DATE_ATOM),
            'ends_at' => $event->getEndsAt()?->format(\DATE_ATOM),
            'locale' => $locale,
        ];
    }
}
