<?php
declare(strict_types=1);

namespace Phpdup\Cli;

/**
 * Sniffs a scan path for framework / CMS markers and picks a
 * matching profile name.
 *
 * Detection order favours specificity: framework-specific markers
 * (artisan, bin/console, web/core/lib/Drupal.php, wp-config.php)
 * win over a generic composer.json. Returns 'generic' as the
 * fallback so callers can always rely on a non-null profile name.
 */
final class ProjectProfileDetector
{
    /** @var list<string> */
    private const KNOWN_PROFILES = [
        'laravel',
        'symfony',
        'drupal',
        'wordpress',
        'myadmin',
        'db-aware-illuminate',
        'db-aware-aura',
        'db-aware-atlas',
        'db-aware-easydb',
        'db-aware-dibi',
        'db-aware-pixie',
        'generic',
    ];

    /**
     * @param list<string> $paths
     */
    public function detect(array $paths): string
    {
        foreach ($paths as $root) {
            $real = realpath($root);
            if ($real === false || !is_dir($real)) {
                continue;
            }
            $hit = $this->detectIn($real);
            if ($hit !== null) {
                return $hit;
            }
        }
        return 'generic';
    }

    public function detectIn(string $root): ?string
    {
        if ($this->any($root, ['artisan', 'app/Http/Kernel.php', 'config/app.php'])) {
            return 'laravel';
        }
        if ($this->any($root, ['bin/console', 'src/Kernel.php', 'config/services.yaml'])) {
            return 'symfony';
        }
        if ($this->any($root, ['core/lib/Drupal.php', 'web/core/lib/Drupal.php'])) {
            return 'drupal';
        }
        if ($this->any($root, ['wp-config.php', 'wp-config-sample.php', 'wp-includes/version.php'])) {
            return 'wordpress';
        }
        if ($this->dirExists($root, ['include/Orm', 'vendor/detain/db_abstraction'])) {
            return 'myadmin';
        }
        if ($this->hasComposerPackage($root, 'illuminate/database')) {
            return 'db-aware-illuminate';
        }
        if ($this->hasComposerPackage($root, 'aura/sql')) {
            return 'db-aware-aura';
        }
        if ($this->hasComposerPackage($root, 'atlasphp/atlas-pdo')) {
            return 'db-aware-atlas';
        }
        if ($this->hasComposerPackage($root, 'josh/aspantier-easy-db')) {
            return 'db-aware-easydb';
        }
        if ($this->hasComposerPackage($root, 'dibi/dibi')) {
            return 'db-aware-dibi';
        }
        if ($this->hasComposerPackage($root, 'usmanhalalit/pixie')) {
            return 'db-aware-pixie';
        }
        if ($this->any($root, ['composer.json'])) {
            return 'generic';
        }
        return null;
    }

    /** @param list<string> $relPaths */
    private function any(string $root, array $relPaths): bool
    {
        foreach ($relPaths as $rel) {
            if (is_file($root . DIRECTORY_SEPARATOR . $rel)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $relPaths */
    private function dirExists(string $root, array $relPaths): bool
    {
        foreach ($relPaths as $rel) {
            if (is_dir($root . DIRECTORY_SEPARATOR . $rel)) {
                return true;
            }
        }
        return false;
    }

    private function hasComposerPackage(string $root, string $package): bool
    {
        $composerFile = $root . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerFile)) {
            return false;
        }
        $content = file_get_contents($composerFile);
        if ($content === false) {
            return false;
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return false;
        }
        $require = $data['require'] ?? [];
        $requireDev = $data['require-dev'] ?? [];
        return isset($require[$package]) || isset($requireDev[$package]);
    }

    /** @return list<string> */
    public static function knownProfiles(): array
    {
        return self::KNOWN_PROFILES;
    }
}
