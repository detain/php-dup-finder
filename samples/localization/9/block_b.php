<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\NotificationTemplateRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class NotificationTemplateLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'pl', 'ru', 'uk', 'tr'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly NotificationTemplateRepository $templateRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedTemplate(string $templateKey, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildTemplateCacheKey($templateKey, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'notification_template', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'notification_template', 'locale' => $locale]);

        $template = $this->templateRepository->findByKey($templateKey);

        if ($template === null) {
            return null;
        }

        $data = $this->translateTemplate($template, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getAllTemplates(?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildAllTemplatesCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $templates = $this->templateRepository->findAll();

        $results = [];
        foreach ($templates as $template) {
            $results[$template->getKey()] = $this->translateTemplate($template, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateTemplate(string $templateKey): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildTemplateCacheKey($templateKey, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildAllTemplatesCacheKey($l));
        }

        $this->logger->debug('Invalidated notification template localization', [
            'template_key' => $templateKey,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('notification_template:*:' . $locale);

        $this->logger->info('Invalidated all notification templates for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateTemplateTranslation(string $templateKey, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildTemplateCacheKey($templateKey, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'notification_template',
            'template_key' => $templateKey,
            'locale' => $locale,
        ]);
    }

    private function buildTemplateCacheKey(string $templateKey, string $locale): string
    {
        return "notification_template:{$templateKey}:{$locale}";
    }

    private function buildAllTemplatesCacheKey(string $locale): string
    {
        return "notification_template:all:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateTemplate(object $template, string $locale): array
    {
        return [
            'key' => $template->getKey(),
            'type' => $template->getType(),
            'subject' => $this->translator->translate($template->getSubjectKey(), $locale),
            'body' => $this->translator->translate($template->getBodyKey(), $locale),
            'sms_body' => $this->translator->translate($template->getSmsBodyKey(), $locale),
            'push_title' => $this->translator->translate($template->getPushTitleKey(), $locale),
            'push_body' => $this->translator->translate($template->getPushBodyKey(), $locale),
            'locale' => $locale,
        ];
    }
}
