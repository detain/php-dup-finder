<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\PolicyDocumentRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class PolicyDocumentLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly PolicyDocumentRepository $policyRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedPolicy(int $policyId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildPolicyCacheKey($policyId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'policy_document', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'policy_document', 'locale' => $locale]);

        $policy = $this->policyRepository->find($policyId);

        if ($policy === null) {
            return null;
        }

        $data = $this->translatePolicy($policy, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getPolicyBySlug(string $slug, ?string $locale = null): ?array
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

        $policy = $this->policyRepository->findBySlug($slug);

        if ($policy === null) {
            return null;
        }

        $data = $this->translatePolicy($policy, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getAllPolicies(?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildAllPoliciesCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $policies = $this->policyRepository->findAll();

        $results = [];
        foreach ($policies as $policy) {
            $results[] = $this->translatePolicy($policy, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidatePolicy(int $policyId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildPolicyCacheKey($policyId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        $policy = $this->policyRepository->find($policyId);
        if ($policy !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $slugKey = $this->buildSlugCacheKey($policy->getSlug(), $l);
                $this->translator->invalidateCache($slugKey);
            }
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildAllPoliciesCacheKey($l));
        }

        $this->logger->debug('Invalidated policy document localization', [
            'policy_id' => $policyId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('policy_document:*:' . $locale);

        $this->logger->info('Invalidated all policy documents for locale', [
            'locale' => $locale,
        ]);
    }

    public function updatePolicyTranslation(int $policyId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildPolicyCacheKey($policyId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $policy = $this->policyRepository->find($policyId);
        if ($policy !== null) {
            $slugKey = $this->buildSlugCacheKey($policy->getSlug(), $locale);
            $this->translator->cacheTranslation($slugKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'policy_document',
            'policy_id' => (string) $policyId,
            'locale' => $locale,
        ]);
    }

    private function buildPolicyCacheKey(int $policyId, string $locale): string
    {
        return "policy_document:{$policyId}:{$locale}";
    }

    private function buildSlugCacheKey(string $slug, string $locale): string
    {
        return "policy_document:slug:{$slug}:{$locale}";
    }

    private function buildAllPoliciesCacheKey(string $locale): string
    {
        return "policy_document:all:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translatePolicy(object $policy, string $locale): array
    {
        return [
            'id' => $policy->getId(),
            'slug' => $policy->getSlug(),
            'type' => $policy->getType(),
            'title' => $this->translator->translate($policy->getTitleKey(), $locale),
            'content' => $this->translator->translate($policy->getContentKey(), $locale),
            'version' => $policy->getVersion(),
            'effective_date' => $policy->getEffectiveDate()?->format(\DATE_ATOM),
            'locale' => $locale,
        ];
    }
}
