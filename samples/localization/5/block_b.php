<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\TestimonialRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class TestimonialLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly TestimonialRepository $testimonialRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedTestimonial(int $testimonialId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildTestimonialCacheKey($testimonialId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'testimonial', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'testimonial', 'locale' => $locale]);

        $testimonial = $this->testimonialRepository->find($testimonialId);

        if ($testimonial === null) {
            return null;
        }

        $data = $this->translateTestimonial($testimonial, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getFeaturedTestimonials(?string $locale = null, int $limit = 5): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildFeaturedCacheKey($locale, $limit);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $testimonials = $this->testimonialRepository->findFeatured($limit);

        $results = [];
        foreach ($testimonials as $testimonial) {
            $results[] = $this->translateTestimonial($testimonial, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateTestimonial(int $testimonialId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildTestimonialCacheKey($testimonialId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $featuredKey = $this->buildFeaturedCacheKey($l, 5);
            $this->translator->invalidateCache($featuredKey);
        }

        $this->logger->debug('Invalidated testimonial localization', [
            'testimonial_id' => $testimonialId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('testimonial:*:' . $locale);

        $this->logger->info('Invalidated all testimonials for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateTestimonialTranslation(int $testimonialId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildTestimonialCacheKey($testimonialId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'testimonial',
            'testimonial_id' => (string) $testimonialId,
            'locale' => $locale,
        ]);
    }

    private function buildTestimonialCacheKey(int $testimonialId, string $locale): string
    {
        return "testimonial:{$testimonialId}:{$locale}";
    }

    private function buildFeaturedCacheKey(string $locale, int $limit): string
    {
        return "testimonial:featured:{$locale}:{$limit}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateTestimonial(object $testimonial, string $locale): array
    {
        return [
            'id' => $testimonial->getId(),
            'author_name' => $testimonial->getAuthorName(),
            'author_title' => $this->translator->translate($testimonial->getAuthorTitleKey(), $locale),
            'content' => $this->translator->translate($testimonial->getContentKey(), $locale),
            'avatar_url' => $testimonial->getAvatarUrl(),
            'rating' => $testimonial->getRating(),
            'locale' => $locale,
        ];
    }
}
