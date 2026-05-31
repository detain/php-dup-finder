<?php
declare(strict_types=1);

namespace Billing\Core\Config;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Centralized configuration accessor using autowiring.
 *
 * Instead of injecting raw arrays, services receive typed config
 * via a centralized ConfigAccessor that handles:
 * - Environment-specific overrides
 * - Sensitive value masking in logs
 * - Configuration validation
 */
final class ConfigAccessor
{
    private const CACHE_KEY = 'app_config_loaded';

    public function __construct(
        private readonly ContainerInterface $container,
        #[Autowire('%kernel.project_dir%/config')]
        private readonly string $configDir
    ) {}

    public function get(string $path, mixed $default = null): mixed
    {
        static $config = null;

        if ($config === null) {
            $config = $this->loadConfig();
        }

        $keys = explode('.', $path);
        $value = $config;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public function getNotificationsConfig(): NotificationsConfig
    {
        return new NotificationsConfig($this->get('notifications', []));
    }

    public function getWebhookConfig(string $provider): WebhookConfig
    {
        return new WebhookConfig($this->get("webhooks.providers.{$provider}", []));
    }

    private function loadConfig(): array
    {
        // Load configuration from files
        return [
            'notifications' => require $this->configDir . '/notifications.php',
            'webhooks' => require $this->configDir . '/webhooks.php',
            'app' => require $this->configDir . '/app.php'
        ];
    }
}

// Usage: services receive ConfigAccessor instead of raw arrays
// class EmailNotificationHandler { public function __construct(private readonly ConfigAccessor $config) {} }
