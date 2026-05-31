<?php
declare(strict_types=1);

namespace SaaS\Tenants\Config;

final class UserConfigLoader
{
    public function __construct(private string $configDir) {}

    /** @return array<string,mixed> */
    public function load(string $userId): array
    {
        $defaultsJson = <<<'JSON'
        {
          "feature_flags": {"beta_ui": false, "ai_assist": true, "exports_v2": false},
          "rate_limits": {"api_per_minute": 600, "search_per_minute": 60, "uploads_per_hour": 100},
          "ui": {"theme": "system", "density": "comfortable", "sidebar_collapsed": false},
          "notifications": {"email_digest": "daily", "in_app": true, "slack_webhook": null},
          "security": {"mfa_required": true, "session_timeout_minutes": 30, "ip_allowlist": []},
          "billing": {"plan": "starter", "currency": "USD", "auto_renew": true},
          "integrations": {"hubspot": false, "salesforce": false, "intercom": false, "zendesk": false}
        }
        JSON;

        /** @var array<string,mixed> $defaults */
        $defaults = json_decode($defaultsJson, true, flags: JSON_THROW_ON_ERROR);

        $path = $this->configDir . "/user-{$userId}.json";
        if (! is_file($path)) {
            return $defaults;
        }
        /** @var array<string,mixed> $stored */
        $stored = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        return array_replace_recursive($defaults, $stored);
    }
}
