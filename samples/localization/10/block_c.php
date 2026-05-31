<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\PodcastEpisodeRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class PodcastEpisodeLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'cs', 'hu', 'ro'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly PodcastEpisodeRepository $episodeRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedEpisode(int $episodeId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildEpisodeCacheKey($episodeId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'podcast_episode', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'podcast_episode', 'locale' => $locale]);

        $episode = $this->episodeRepository->find($episodeId);

        if ($episode === null) {
            return null;
        }

        $data = $this->translateEpisode($episode, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getEpisodeBySlug(string $slug, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildSlugCacheKey($slug, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $episode = $this->episodeRepository->findBySlug($slug);

        if ($episode === null) {
            return null;
        }

        $data = $this->translateEpisode($episode, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getEpisodesByPodcast(string $podcastSlug, ?string $locale = null, int $limit = 20): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildPodcastEpisodesCacheKey($podcastSlug, $locale, $limit);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $episodes = $this->episodeRepository->findByPodcastSlug($podcastSlug, $limit);

        $results = [];
        foreach ($episodes as $episode) {
            $results[] = $this->translateEpisode($episode, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateEpisode(int $episodeId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildEpisodeCacheKey($episodeId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        $episode = $this->episodeRepository->find($episodeId);
        if ($episode !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $slugKey = $this->buildSlugCacheKey($episode->getSlug(), $l);
                $this->translator->invalidateCache($slugKey);
            }
        }

        $this->logger->debug('Invalidated podcast episode localization', [
            'episode_id' => $episodeId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('podcast_episode:*:' . $locale);

        $this->logger->info('Invalidated all podcast episodes for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateEpisodeTranslation(int $episodeId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildEpisodeCacheKey($episodeId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $episode = $this->episodeRepository->find($episodeId);
        if ($episode !== null) {
            $slugKey = $this->buildSlugCacheKey($episode->getSlug(), $locale);
            $this->translator->cacheTranslation($slugKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'podcast_episode',
            'episode_id' => (string) $episodeId,
            'locale' => $locale,
        ]);
    }

    private function buildEpisodeCacheKey(int $episodeId, string $locale): string
    {
        return "podcast_episode:{$episodeId}:{$locale}";
    }

    private function buildSlugCacheKey(string $slug, string $locale): string
    {
        return "podcast_episode:slug:{$slug}:{$locale}";
    }

    private function buildPodcastEpisodesCacheKey(string $podcastSlug, string $locale, int $limit): string
    {
        return "podcast_episode:podcast:{$podcastSlug}:{$locale}:{$limit}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateEpisode(object $episode, string $locale): array
    {
        return [
            'id' => $episode->getId(),
            'slug' => $episode->getSlug(),
            'podcast_slug' => $episode->getPodcastSlug(),
            'episode_number' => $episode->getEpisodeNumber(),
            'season_number' => $episode->getSeasonNumber(),
            'title' => $this->translator->translate($episode->getTitleKey(), $locale),
            'description' => $this->translator->translate($episode->getDescriptionKey(), $locale),
            'show_notes' => $this->translator->translate($episode->getShowNotesKey(), $locale),
            'duration_seconds' => $episode->getDurationSeconds(),
            'published_at' => $episode->getPublishedAt()?->format(\DATE_ATOM),
            'locale' => $locale,
        ];
    }
}
