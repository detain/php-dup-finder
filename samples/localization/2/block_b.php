<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\EmailTemplateRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class EmailTemplateLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'zh', 'ko', 'ar'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly EmailTemplateRepository $templateRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedTemplate(int $templateId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildTemplateCacheKey($templateId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'email_template', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'email_template', 'locale' => $locale]);

        $template = $this->templateRepository->find($templateId);

        if ($template === null) {
            return null;
        }

        $data = $this->translateTemplate($template, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getTemplateByIdentifier(string $identifier, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildIdentifierCacheKey($identifier, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $template = $this->templateRepository->findByIdentifier($identifier);

        if ($template === null) {
            return null;
        }

        $data = $this->translateTemplate($template, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function invalidateTemplate(int $templateId): void
    {
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildTemplateCacheKey($templateId, $locale);
            $this->translator->invalidateCache($cacheKey);
        }

        $template = $this->templateRepository->find($templateId);
        if ($template !== null) {
            foreach (self::SUPPORTED_LOCALES as $locale) {
                $identifierKey = $this->buildIdentifierCacheKey($template->getIdentifier(), $locale);
                $this->translator->invalidateCache($identifierKey);
            }
        }

        $this->logger->debug('Invalidated email template localization', [
            'template_id' => $templateId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('email_template:*:' . $locale);
        $this->translator->invalidateCacheByPattern('email_template_identifier:*:' . $locale);

        $this->logger->info('Invalidated all email templates for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateTemplateTranslation(int $templateId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildTemplateCacheKey($templateId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $template = $this->templateRepository->find($templateId);
        if ($template !== null) {
            $identifierKey = $this->buildIdentifierCacheKey($template->getIdentifier(), $locale);
            $this->translator->cacheTranslation($identifierKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'email_template',
            'template_id' => (string) $templateId,
            'locale' => $locale,
        ]);
    }

    public function renderTemplate(string $identifier, array $variables, ?string $locale = null): ?string
    {
        $template = $this->getTemplateByIdentifier($identifier, $locale);

        if ($template === null) {
            return null;
        }

        $subject = $this->replaceVariables($template['subject'], $variables);
        $body = $this->replaceVariables($template['body'], $variables);

        return "Subject: {$subject}\n\n{$body}";
    }

    public function getAvailableTranslations(int $templateId): array
    {
        $translations = [];

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildTemplateCacheKey($templateId, $locale);
            $cached = $this->translator->getCachedTranslation($cacheKey);
            $translations[$locale] = $cached !== null;
        }

        return $translations;
    }

    public function getMissingTranslations(int $templateId): array
    {
        $available = $this->getAvailableTranslations($templateId);
        $missing = [];

        foreach ($available as $locale => $exists) {
            if (!$exists) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    public function warmCacheForTemplate(int $templateId): void
    {
        $template = $this->templateRepository->find($templateId);

        if ($template === null) {
            return;
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $data = $this->translateTemplate($template, $locale);
            $this->translator->cacheTranslation($this->buildTemplateCacheKey($templateId, $locale), $data);
            $this->translator->cacheTranslation($this->buildIdentifierCacheKey($template->getIdentifier(), $locale), $data);
        }

        $this->logger->debug('Warmed localization cache for email template', [
            'template_id' => $templateId,
        ]);
    }

    private function buildTemplateCacheKey(int $templateId, string $locale): string
    {
        return "email_template:{$templateId}:{$locale}";
    }

    private function buildIdentifierCacheKey(string $identifier, string $locale): string
    {
        return "email_template_identifier:{$identifier}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateTemplate(object $template, string $locale): array
    {
        return [
            'id' => $template->getId(),
            'identifier' => $template->getIdentifier(),
            'subject' => $this->translator->translate($template->getSubjectKey(), $locale),
            'body' => $this->translator->translate($template->getBodyKey(), $locale),
            'template_type' => $template->getTemplateType(),
            'locale' => $locale,
        ];
    }

    private function replaceVariables(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
        }
        return $content;
    }
}
