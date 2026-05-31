<?php
declare(strict_types=1);

namespace SaaS\Tenants\Config;

final class DefaultConfig
{
    /** @return array<string,mixed> */
    public static function values(): array
    {
        // Single source of truth — checked into `config/defaults.json`.
        /** @var array<string,mixed> $defaults */
        $defaults = json_decode(
            (string) file_get_contents(__DIR__ . '/../../config/defaults.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        return $defaults;
    }
}

final class TenantConfigLoader
{
    public function __construct(private \PDO $db) {}

    /** @return array<string,mixed> */
    public function load(string $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT config_json FROM tenant_config WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (! $row) {
            return DefaultConfig::values();
        }
        /** @var array<string,mixed> $stored */
        $stored = json_decode($row['config_json'], true, flags: JSON_THROW_ON_ERROR);
        return array_replace_recursive(DefaultConfig::values(), $stored);
    }
}

final class WorkspaceConfigLoader
{
    public function __construct(private \Redis $redis) {}

    /** @return array<string,mixed> */
    public function load(string $workspaceId): array
    {
        $cached = $this->redis->get("workspace:cfg:{$workspaceId}");
        if (! is_string($cached)) {
            return DefaultConfig::values();
        }
        /** @var array<string,mixed> $stored */
        $stored = json_decode($cached, true, flags: JSON_THROW_ON_ERROR);
        return array_replace_recursive(DefaultConfig::values(), $stored);
    }
}
