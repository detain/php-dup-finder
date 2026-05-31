<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\ErrorMessageRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ErrorMessageLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'zh'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly ErrorMessageRepository $errorRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedError(string $errorCode, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildErrorCacheKey($errorCode, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'error_message', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'error_message', 'locale' => $locale]);

        $error = $this->errorRepository->findByCode($errorCode);

        if ($error === null) {
            return null;
        }

        $data = $this->translateError($error, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getAllErrors(?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildAllErrorsCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $errors = $this->errorRepository->findAll();

        $results = [];
        foreach ($errors as $error) {
            $results[$error->getCode()] = $this->translateError($error, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function getErrorsByCategory(string $category, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildCategoryErrorCacheKey($category, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $errors = $this->errorRepository->findByCategory($category);

        $results = [];
        foreach ($errors as $error) {
            $results[$error->getCode()] = $this->translateError($error, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateError(string $errorCode): void
    {
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildErrorCacheKey($errorCode, $locale);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $allKey = $this->buildAllErrorsCacheKey($locale);
            $this->translator->invalidateCache($allKey);
        }

        $error = $this->errorRepository->findByCode($errorCode);
        if ($error !== null) {
            foreach (self::SUPPORTED_LOCALES as $locale) {
                $categoryKey = $this->buildCategoryErrorCacheKey($error->getCategory(), $locale);
                $this->translator->invalidateCache($categoryKey);
            }
        }

        $this->logger->debug('Invalidated error message localization', [
            'error_code' => $errorCode,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('error_message:*:' . $locale);

        $this->logger->info('Invalidated all error messages for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateErrorTranslation(string $errorCode, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildErrorCacheKey($errorCode, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildAllErrorsCacheKey($l));
        }

        $error = $this->errorRepository->findByCode($errorCode);
        if ($error !== null) {
            $this->translator->invalidateCache($this->buildCategoryErrorCacheKey($error->getCategory(), $locale));
        }

        $this->metrics->increment('localization.update', [
            'type' => 'error_message',
            'error_code' => $errorCode,
            'locale' => $locale,
        ]);
    }

    public function formatError(string $errorCode, array $variables, ?string $locale = null): ?string
    {
        $error = $this->getLocalizedError($errorCode, $locale);

        if ($error === null) {
            return null;
        }

        $message = $error['message'];
        foreach ($variables as $key => $value) {
            $message = str_replace('{{' . $key . '}}', (string) $value, $message);
        }

        return $message;
    }

    public function getHttpStatusCode(string $errorCode, ?string $locale = null): int
    {
        $error = $this->getLocalizedError($errorCode, $locale);

        if ($error === null) {
            return 500;
        }

        return $error['http_status'] ?? 500;
    }

    private function buildErrorCacheKey(string $errorCode, string $locale): string
    {
        return "error_message:{$errorCode}:{$locale}";
    }

    private function buildAllErrorsCacheKey(string $locale): string
    {
        return "error_message:all:{$locale}";
    }

    private function buildCategoryErrorCacheKey(string $category, string $locale): string
    {
        return "error_message:category:{$category}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateError(object $error, string $locale): array
    {
        return [
            'code' => $error->getCode(),
            'category' => $error->getCategory(),
            'message' => $this->translator->translate($error->getMessageKey(), $locale),
            'description' => $this->translator->translate($error->getDescriptionKey(), $locale),
            'http_status' => $error->getHttpStatus(),
            'locale' => $locale,
        ];
    }
}
