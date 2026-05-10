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
        'db-aware-doctrine',
        'db-aware-cycle',
        'db-aware-propel',
        'db-aware-redbean',
        'db-aware-cake',
        'db-aware-medoo',
        'db-aware-phpactiverecord',
        'db-aware-thinkorm',
        'db-aware-redis',
        'db-aware-mongodb',
        'db-aware-elasticsearch',
        'db-aware-neo4j',
        'db-aware-influxdb',
        'db-aware-couchdb',
        'db-aware-couchbase',
        'db-aware-idiorm',
        'db-aware-phalcon',
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
        if ($this->hasComposerPackage($root, 'doctrine/orm') || $this->hasComposerPackage($root, 'doctrine/doctrine-bundle')) {
            return 'db-aware-doctrine';
        }
        if ($this->hasComposerPackage($root, 'cycle/orm')) {
            return 'db-aware-cycle';
        }
        if ($this->hasComposerPackage($root, 'propel/propel') || $this->hasComposerPackage($root, 'propelorm/propel')) {
            return 'db-aware-propel';
        }
        if ($this->hasComposerPackage($root, 'gabordemooij/redbean')) {
            return 'db-aware-redbean';
        }
        if ($this->hasComposerPackage($root, 'cakephp/orm')) {
            return 'db-aware-cake';
        }
        if ($this->hasComposerPackage($root, 'catfan/medoo')) {
            return 'db-aware-medoo';
        }
        if ($this->hasComposerPackage($root, 'php-activerecord/php-activerecord')) {
            return 'db-aware-phpactiverecord';
        }
        if ($this->hasComposerPackage($root, 'topthink/think-orm')) {
            return 'db-aware-thinkorm';
        }
        if ($this->hasComposerPackage($root, 'predis/predis') || $this->hasComposerPackage($root, 'colinmollenhour/credis') || $this->hasComposerPackage($root, 'phpredis/phpredis')) {
            return 'db-aware-redis';
        }
        if ($this->hasComposerPackage($root, 'mongodb/mongodb')) {
            return 'db-aware-mongodb';
        }
        if ($this->hasComposerPackage($root, 'elasticsearch/elasticsearch')) {
            return 'db-aware-elasticsearch';
        }
        if ($this->hasComposerPackage($root, 'laudis/neo4j-php-client')) {
            return 'db-aware-neo4j';
        }
        if ($this->hasComposerPackage($root, 'influxdata/influxdb-client-php')) {
            return 'db-aware-influxdb';
        }
        if ($this->hasComposerPackage($root, 'doctrine/couchdb-odm')) {
            return 'db-aware-couchdb';
        }
        if ($this->hasComposerPackage($root, 'couchbase/couchbase')) {
            return 'db-aware-couchbase';
        }
        if ($this->hasComposerPackage($root, 'j4mie/idiorm') || $this->hasComposerPackage($root, 'paris/paris')) {
            return 'db-aware-idiorm';
        }
        if ($this->hasComposerPackage($root, 'phalcon/cphalcon')) {
            return 'db-aware-phalcon';
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
