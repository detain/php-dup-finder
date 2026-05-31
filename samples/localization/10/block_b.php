<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\CourseRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class CourseLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'cs', 'hu', 'ro'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly CourseRepository $courseRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedCourse(int $courseId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildCourseCacheKey($courseId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'course', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'course', 'locale' => $locale]);

        $course = $this->courseRepository->find($courseId);

        if ($course === null) {
            return null;
        }

        $data = $this->translateCourse($course, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getCourseBySlug(string $slug, ?string $locale = null): ?array
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

        $course = $this->courseRepository->findBySlug($slug);

        if ($course === null) {
            return null;
        }

        $data = $this->translateCourse($course, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getFeaturedCourses(?string $locale = null, int $limit = 10): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildFeaturedCoursesCacheKey($locale, $limit);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $courses = $this->courseRepository->findFeatured($limit);

        $results = [];
        foreach ($courses as $course) {
            $results[] = $this->translateCourse($course, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function getCoursesByCategory(string $category, ?string $locale = null, int $limit = 20): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildCategoryCacheKey($category, $locale, $limit);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $courses = $this->courseRepository->findByCategory($category, $limit);

        $results = [];
        foreach ($courses as $course) {
            $results[] = $this->translateCourse($course, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateCourse(int $courseId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildCourseCacheKey($courseId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        $course = $this->courseRepository->find($courseId);
        if ($course !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $slugKey = $this->buildSlugCacheKey($course->getSlug(), $l);
                $this->translator->invalidateCache($slugKey);
            }
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildFeaturedCoursesCacheKey($l, 10));
        }

        $this->logger->debug('Invalidated course localization', [
            'course_id' => $courseId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('course:*:' . $locale);

        $this->logger->info('Invalidated all courses for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateCourseTranslation(int $courseId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildCourseCacheKey($courseId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $course = $this->courseRepository->find($courseId);
        if ($course !== null) {
            $slugKey = $this->buildSlugCacheKey($course->getSlug(), $locale);
            $this->translator->cacheTranslation($slugKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'course',
            'course_id' => (string) $courseId,
            'locale' => $locale,
        ]);
    }

    private function buildCourseCacheKey(int $courseId, string $locale): string
    {
        return "course:{$courseId}:{$locale}";
    }

    private function buildSlugCacheKey(string $slug, string $locale): string
    {
        return "course:slug:{$slug}:{$locale}";
    }

    private function buildFeaturedCoursesCacheKey(string $locale, int $limit): string
    {
        return "course:featured:{$locale}:{$limit}";
    }

    private function buildCategoryCacheKey(string $category, string $locale, int $limit): string
    {
        return "course:category:{$category}:{$locale}:{$limit}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateCourse(object $course, string $locale): array
    {
        return [
            'id' => $course->getId(),
            'slug' => $course->getSlug(),
            'category' => $course->getCategory(),
            'title' => $this->translator->translate($course->getTitleKey(), $locale),
            'description' => $this->translator->translate($course->getDescriptionKey(), $locale),
            'syllabus' => $this->translator->translate($course->getSyllabusKey(), $locale),
            'instructor' => $course->getInstructor(),
            'duration_hours' => $course->getDurationHours(),
            'difficulty_level' => $course->getDifficultyLevel(),
            'price' => $course->getPrice(),
            'currency' => $course->getCurrency(),
            'locale' => $locale,
        ];
    }
}
