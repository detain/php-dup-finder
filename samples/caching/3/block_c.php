<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\NotificationTemplateRepository;
use App\Repository\EmailTemplateRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class TemplateCacheHandler
{
    private const CACHE_PREFIX = 'template';
    private const DEFAULT_TTL = 86400;
    private const STALE_TTL = 14400;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly NotificationTemplateRepository $notificationTemplateRepository,
        private readonly EmailTemplateRepository $emailTemplateRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getNotificationTemplate(string $templateCode, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildNotificationTemplateCacheKey($templateCode);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'notification_template']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'notification_template']);

        $template = $this->notificationTemplateRepository->findByCode($templateCode);

        if ($template === null) {
            return null;
        }

        $data = $this->serializeNotificationTemplate($template);
        $this->setNotificationTemplate($templateCode, $data);

        return $data;
    }

    public function setNotificationTemplate(string $templateCode, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildNotificationTemplateCacheKey($templateCode);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached notification template', [
            'template_code' => $templateCode,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateNotificationTemplate(string $templateCode): void
    {
        $cacheKey = $this->buildNotificationTemplateCacheKey($templateCode);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated notification template cache', [
            'template_code' => $templateCode,
        ]);
    }

    public function refreshNotificationTemplate(string $templateCode): void
    {
        $template = $this->notificationTemplateRepository->findByCode($templateCode);

        if ($template === null) {
            $this->cache->delete($this->buildNotificationTemplateCacheKey($templateCode));
            return;
        }

        $data = $this->serializeNotificationTemplate($template);
        $this->setNotificationTemplate($templateCode, $data);

        $this->logger->debug('Refreshed notification template cache', [
            'template_code' => $templateCode,
        ]);
    }

    public function warmNotificationTemplates(array $templateCodes): void
    {
        foreach ($templateCodes as $code) {
            $template = $this->notificationTemplateRepository->findByCode($code);

            if ($template !== null) {
                $data = $this->serializeNotificationTemplate($template);
                $this->setNotificationTemplate($code, $data, self::DEFAULT_TTL);
            }
        }

        $this->logger->debug('Warmed notification template cache', [
            'templates_warmed' => count($templateCodes),
        ]);
    }

    public function getEmailTemplate(string $templateCode, string $locale, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildEmailTemplateCacheKey($templateCode, $locale);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'email_template']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'email_template']);

        $template = $this->emailTemplateRepository->findByCodeAndLocale($templateCode, $locale);

        if ($template === null) {
            $template = $this->emailTemplateRepository->findByCodeAndLocale($templateCode, 'en');
        }

        if ($template === null) {
            return null;
        }

        $data = $this->serializeEmailTemplate($template);
        $this->setEmailTemplate($templateCode, $locale, $data);

        return $data;
    }

    public function setEmailTemplate(string $templateCode, string $locale, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildEmailTemplateCacheKey($templateCode, $locale);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached email template', [
            'template_code' => $templateCode,
            'locale' => $locale,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateEmailTemplate(string $templateCode, string $locale): void
    {
        $cacheKey = $this->buildEmailTemplateCacheKey($templateCode, $locale);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated email template cache', [
            'template_code' => $templateCode,
            'locale' => $locale,
        ]);
    }

    public function refreshEmailTemplate(string $templateCode, string $locale): void
    {
        $template = $this->emailTemplateRepository->findByCodeAndLocale($templateCode, $locale);

        if ($template === null) {
            $this->cache->delete($this->buildEmailTemplateCacheKey($templateCode, $locale));
            return;
        }

        $data = $this->serializeEmailTemplate($template);
        $this->setEmailTemplate($templateCode, $locale, $data);

        $this->logger->debug('Refreshed email template cache', [
            'template_code' => $templateCode,
            'locale' => $locale,
        ]);
    }

    public function warmEmailTemplates(string $templateCode, array $locales): void
    {
        foreach ($locales as $locale) {
            $template = $this->emailTemplateRepository->findByCodeAndLocale($templateCode, $locale);

            if ($template !== null) {
                $data = $this->serializeEmailTemplate($template);
                $this->setEmailTemplate($templateCode, $locale, $data, self::DEFAULT_TTL);
            }
        }

        $this->logger->debug('Warmed email template cache', [
            'template_code' => $templateCode,
            'locales_warmed' => count($locales),
        ]);
    }

    public function getSmsTemplate(string $templateCode, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildSmsTemplateCacheKey($templateCode);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'sms_template']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'sms_template']);

        $template = $this->notificationTemplateRepository->findSmsTemplateByCode($templateCode);

        if ($template === null) {
            return null;
        }

        $data = $this->serializeSmsTemplate($template);
        $this->setSmsTemplate($templateCode, $data);

        return $data;
    }

    public function setSmsTemplate(string $templateCode, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildSmsTemplateCacheKey($templateCode);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached SMS template', [
            'template_code' => $templateCode,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateSmsTemplate(string $templateCode): void
    {
        $cacheKey = $this->buildSmsTemplateCacheKey($templateCode);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated SMS template cache', [
            'template_code' => $templateCode,
        ]);
    }

    public function refreshSmsTemplate(string $templateCode): void
    {
        $template = $this->notificationTemplateRepository->findSmsTemplateByCode($templateCode);

        if ($template === null) {
            $this->cache->delete($this->buildSmsTemplateCacheKey($templateCode));
            return;
        }

        $data = $this->serializeSmsTemplate($template);
        $this->setSmsTemplate($templateCode, $data);

        $this->logger->debug('Refreshed SMS template cache', [
            'template_code' => $templateCode,
        ]);
    }

    public function warmSmsTemplates(array $templateCodes): void
    {
        foreach ($templateCodes as $code) {
            $template = $this->notificationTemplateRepository->findSmsTemplateByCode($code);

            if ($template !== null) {
                $data = $this->serializeSmsTemplate($template);
                $this->setSmsTemplate($code, $data, self::DEFAULT_TTL);
            }
        }

        $this->logger->debug('Warmed SMS template cache', [
            'templates_warmed' => count($templateCodes),
        ]);
    }

    public function handleNotificationTemplateChange(string $templateCode): void
    {
        $this->invalidateNotificationTemplate($templateCode);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'notification_template_change',
            'template_code' => $templateCode,
        ]);

        $this->logger->info('Handled notification template change cache invalidation', [
            'template_code' => $templateCode,
        ]);
    }

    public function handleEmailTemplateChange(string $templateCode, string $locale): void
    {
        $this->invalidateEmailTemplate($templateCode, $locale);

        $allLocales = $this->emailTemplateRepository->findLocalesForTemplate($templateCode);
        foreach ($allLocales as $loc) {
            $this->invalidateEmailTemplate($templateCode, $loc);
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'email_template_change',
            'template_code' => $templateCode,
            'locale' => $locale,
        ]);

        $this->logger->info('Handled email template change cache invalidation', [
            'template_code' => $templateCode,
            'locale' => $locale,
        ]);
    }

    public function handleSmsTemplateChange(string $templateCode): void
    {
        $this->invalidateSmsTemplate($templateCode);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'sms_template_change',
            'template_code' => $templateCode,
        ]);

        $this->logger->info('Handled SMS template change cache invalidation', [
            'template_code' => $templateCode,
        ]);
    }

    public function handleGlobalTemplateUpdate(): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'notification', '*');
        $this->cache->deleteByPattern($pattern);

        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'email', '*');
        $this->cache->deleteByPattern($pattern);

        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'sms', '*');
        $this->cache->deleteByPattern($pattern);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'global_template_update',
        ]);

        $this->logger->info('Handled global template update cache invalidation');
    }

    private function buildNotificationTemplateCacheKey(string $templateCode): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'notification', $templateCode);
    }

    private function buildEmailTemplateCacheKey(string $templateCode, string $locale): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'email', $templateCode, $locale);
    }

    private function buildSmsTemplateCacheKey(string $templateCode): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'sms', $templateCode);
    }

    private function serializeNotificationTemplate(object $template): array
    {
        return [
            'code' => $template->getCode(),
            'name' => $template->getName(),
            'subject' => $template->getSubject(),
            'body' => $template->getBody(),
            'type' => $template->getType(),
            'variables' => $template->getVariables(),
        ];
    }

    private function serializeEmailTemplate(object $template): array
    {
        return [
            'code' => $template->getCode(),
            'subject' => $template->getSubject(),
            'body' => $template->getBody(),
            'html_body' => $template->getHtmlBody(),
            'from_name' => $template->getFromName(),
            'from_email' => $template->getFromEmail(),
            'reply_to' => $template->getReplyTo(),
        ];
    }

    private function serializeSmsTemplate(object $template): array
    {
        return [
            'code' => $template->getCode(),
            'body' => $template->getBody(),
            'max_length' => $template->getMaxLength(),
            'variables' => $template->getVariables(),
        ];
    }
}
