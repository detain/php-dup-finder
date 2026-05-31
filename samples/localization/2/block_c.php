<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\ValidationMessageRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ValidationMessageLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'zh', 'ko', 'ar'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly ValidationMessageRepository $messageRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedMessage(string $ruleName, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildMessageCacheKey($ruleName, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'validation_message', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'validation_message', 'locale' => $locale]);

        $message = $this->messageRepository->findByRuleName($ruleName);

        if ($message === null) {
            return null;
        }

        $data = $this->translateMessage($message, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getAllMessages(?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildAllMessagesCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $messages = $this->messageRepository->findAll();

        $results = [];
        foreach ($messages as $message) {
            $results[$message->getRuleName()] = $this->translateMessage($message, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateMessage(string $ruleName): void
    {
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildMessageCacheKey($ruleName, $locale);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $allKey = $this->buildAllMessagesCacheKey($locale);
            $this->translator->invalidateCache($allKey);
        }

        $this->logger->debug('Invalidated validation message localization', [
            'rule_name' => $ruleName,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('validation_message:*:' . $locale);

        $this->logger->info('Invalidated all validation messages for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateMessageTranslation(string $ruleName, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildMessageCacheKey($ruleName, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $allKey = $this->buildAllMessagesCacheKey($locale);
        $this->translator->invalidateCache($allKey);

        $this->metrics->increment('localization.update', [
            'type' => 'validation_message',
            'rule_name' => $ruleName,
            'locale' => $locale,
        ]);
    }

    public function formatMessage(string $ruleName, array $variables, ?string $locale = null): ?string
    {
        $message = $this->getLocalizedMessage($ruleName, $locale);

        if ($message === null) {
            return null;
        }

        $template = $message['template'];
        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }

        return $template;
    }

    public function getFieldLabel(string $fieldName, ?string $locale = null): string
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        $cacheKey = $this->buildFieldLabelCacheKey($fieldName, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $labelKey = "field.{$fieldName}.label";
        $translated = $this->translator->translate($labelKey, $locale);

        $this->translator->cacheTranslation($cacheKey, $translated);

        return $translated;
    }

    public function getFieldHelpText(string $fieldName, ?string $locale = null): ?string
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        $cacheKey = $this->buildFieldHelpTextCacheKey($fieldName, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        return $cached;
    }

    public function warmCacheForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $messages = $this->messageRepository->findAll();

        foreach ($messages as $message) {
            $data = $this->translateMessage($message, $locale);
            $this->translator->cacheTranslation(
                $this->buildMessageCacheKey($message->getRuleName(), $locale),
                $data
            );
        }

        $allMessages = [];
        foreach ($messages as $message) {
            $allMessages[$message->getRuleName()] = $this->translateMessage($message, $locale);
        }
        $this->translator->cacheTranslation($this->buildAllMessagesCacheKey($locale), $allMessages);

        $this->logger->debug('Warmed localization cache for validation messages', [
            'locale' => $locale,
        ]);
    }

    private function buildMessageCacheKey(string $ruleName, string $locale): string
    {
        return "validation_message:{$ruleName}:{$locale}";
    }

    private function buildAllMessagesCacheKey(string $locale): string
    {
        return "validation_message:all:{$locale}";
    }

    private function buildFieldLabelCacheKey(string $fieldName, string $locale): string
    {
        return "field_label:{$fieldName}:{$locale}";
    }

    private function buildFieldHelpTextCacheKey(string $fieldName, string $locale): string
    {
        return "field_help:{$fieldName}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateMessage(object $message, string $locale): array
    {
        return [
            'rule_name' => $message->getRuleName(),
            'template' => $this->translator->translate($message->getTemplateKey(), $locale),
            'locale' => $locale,
        ];
    }
}
