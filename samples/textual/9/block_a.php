<?php
declare(strict_types=1);

namespace SaaS\Tenants\Config;

final class TenantConfigLoader
{
    public function __construct(private \PDO $db) {}

    /** @return array<string,mixed> */
    public function load(string $tenantId): array
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

        $stmt = $this->db->prepare('SELECT config_json FROM tenant_config WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (! $row) {
            return $defaults;
        }
        /** @var array<string,mixed> $stored */
        $stored = json_decode($row['config_json'], true, flags: JSON_THROW_ON_ERROR);
        return array_replace_recursive($defaults, $stored);
    }
}
