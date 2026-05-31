<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\JobListingRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class JobListingLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'cs', 'hu', 'ro'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly JobListingRepository $listingRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedListing(int $listingId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildListingCacheKey($listingId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'job_listing', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'job_listing', 'locale' => $locale]);

        $listing = $this->listingRepository->find($listingId);

        if ($listing === null) {
            return null;
        }

        $data = $this->translateListing($listing, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getListingBySlug(string $slug, ?string $locale = null): ?array
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

        $listing = $this->listingRepository->findBySlug($slug);

        if ($listing === null) {
            return null;
        }

        $data = $this->translateListing($listing, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getActiveListings(?string $locale = null, int $limit = 20): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildActiveListingsCacheKey($locale, $limit);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $listings = $this->listingRepository->findActive($limit);

        $results = [];
        foreach ($listings as $listing) {
            $results[] = $this->translateListing($listing, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function getListingsByDepartment(string $department, ?string $locale = null, int $limit = 20): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildDepartmentCacheKey($department, $locale, $limit);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $listings = $this->listingRepository->findByDepartment($department, $limit);

        $results = [];
        foreach ($listings as $listing) {
            $results[] = $this->translateListing($listing, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateListing(int $listingId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildListingCacheKey($listingId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        $listing = $this->listingRepository->find($listingId);
        if ($listing !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $slugKey = $this->buildSlugCacheKey($listing->getSlug(), $l);
                $this->translator->invalidateCache($slugKey);
            }
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildActiveListingsCacheKey($l, 20));
        }

        $this->logger->debug('Invalidated job listing localization', [
            'listing_id' => $listingId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('job_listing:*:' . $locale);

        $this->logger->info('Invalidated all job listings for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateListingTranslation(int $listingId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildListingCacheKey($listingId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $listing = $this->listingRepository->find($listingId);
        if ($listing !== null) {
            $slugKey = $this->buildSlugCacheKey($listing->getSlug(), $locale);
            $this->translator->cacheTranslation($slugKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'job_listing',
            'listing_id' => (string) $listingId,
            'locale' => $locale,
        ]);
    }

    public function getAvailableTranslations(int $listingId): array
    {
        $translations = [];

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildListingCacheKey($listingId, $locale);
            $cached = $this->translator->getCachedTranslation($cacheKey);
            $translations[$locale] = $cached !== null;
        }

        return $translations;
    }

    public function getMissingTranslations(int $listingId): array
    {
        $available = $this->getAvailableTranslations($listingId);
        $missing = [];

        foreach ($available as $locale => $exists) {
            if (!$exists) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    private function buildListingCacheKey(int $listingId, string $locale): string
    {
        return "job_listing:{$listingId}:{$locale}";
    }

    private function buildSlugCacheKey(string $slug, string $locale): string
    {
        return "job_listing:slug:{$slug}:{$locale}";
    }

    private function buildActiveListingsCacheKey(string $locale, int $limit): string
    {
        return "job_listing:active:{$locale}:{$limit}";
    }

    private function buildDepartmentCacheKey(string $department, string $locale, int $limit): string
    {
        return "job_listing:department:{$department}:{$locale}:{$limit}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateListing(object $listing, string $locale): array
    {
        return [
            'id' => $listing->getId(),
            'slug' => $listing->getSlug(),
            'department' => $listing->getDepartment(),
            'title' => $this->translator->translate($listing->getTitleKey(), $locale),
            'description' => $this->translator->translate($listing->getDescriptionKey(), $locale),
            'requirements' => $this->translator->translate($listing->getRequirementsKey(), $locale),
            'benefits' => $this->translator->translate($listing->getBenefitsKey(), $locale),
            'location' => $this->translator->translate($listing->getLocationKey(), $locale),
            'employment_type' => $listing->getEmploymentType(),
            'salary_min' => $listing->getSalaryMin(),
            'salary_max' => $listing->getSalaryMax(),
            'salary_currency' => $listing->getSalaryCurrency(),
            'posted_at' => $listing->getPostedAt()?->format(\DATE_ATOM),
            'expires_at' => $listing->getExpiresAt()?->format(\DATE_ATOM),
            'locale' => $locale,
        ];
    }
}
