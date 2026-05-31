<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\GiftCardRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class GiftCardLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly GiftCardRepository $giftCardRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedGiftCard(int $giftCardId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildGiftCardCacheKey($giftCardId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'gift_card', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'gift_card', 'locale' => $locale]);

        $giftCard = $this->giftCardRepository->find($giftCardId);

        if ($giftCard === null) {
            return null;
        }

        $data = $this->translateGiftCard($giftCard, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getGiftCardByCode(string $code, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildCodeCacheKey($code, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $giftCard = $this->giftCardRepository->findByCode($code);

        if ($giftCard === null) {
            return null;
        }

        $data = $this->translateGiftCard($giftCard, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function invalidateGiftCard(int $giftCardId): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildGiftCardCacheKey($giftCardId, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        $giftCard = $this->giftCardRepository->find($giftCardId);
        if ($giftCard !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $codeKey = $this->buildCodeCacheKey($giftCard->getCode(), $l);
                $this->translator->invalidateCache($codeKey);
            }
        }

        $this->logger->debug('Invalidated gift card localization', [
            'gift_card_id' => $giftCardId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('gift_card:*:' . $locale);

        $this->logger->info('Invalidated all gift cards for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateGiftCardTranslation(int $giftCardId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildGiftCardCacheKey($giftCardId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'gift_card',
            'gift_card_id' => (string) $giftCardId,
            'locale' => $locale,
        ]);
    }

    private function buildGiftCardCacheKey(int $giftCardId, string $locale): string
    {
        return "gift_card:{$giftCardId}:{$locale}";
    }

    private function buildCodeCacheKey(string $code, string $locale): string
    {
        return "gift_card:code:{$code}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateGiftCard(object $giftCard, string $locale): array
    {
        return [
            'id' => $giftCard->getId(),
            'code' => $giftCard->getCode(),
            'design' => $this->translator->translate($giftCard->getDesignKey(), $locale),
            'message' => $this->translator->translate($giftCard->getMessageKey(), $locale),
            'sender_name' => $giftCard->getSenderName(),
            'recipient_name' => $giftCard->getRecipientName(),
            'amount' => $giftCard->getAmount(),
            'currency' => $giftCard->getCurrency(),
            'locale' => $locale,
        ];
    }
}
