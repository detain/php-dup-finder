<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\ProfileRegistry;
use Phpdup\Cli\ProjectProfileDetector;

final class ProjectProfileDetectorTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            $this->rrmdir($dir);
        }
        $this->cleanup = [];
    }

    public function testLaravelMarkerDetectsLaravel(): void
    {
        $root = $this->mkproject(['artisan' => '#!/usr/bin/env php']);
        $this->assertSame('laravel', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testSymfonyMarkerDetectsSymfony(): void
    {
        $root = $this->mkproject(['bin/console' => '#!/usr/bin/env php']);
        $this->assertSame('symfony', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testDrupalMarkerDetectsDrupal(): void
    {
        $root = $this->mkproject(['core/lib/Drupal.php' => '<?php class Drupal {}']);
        $this->assertSame('drupal', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testWordPressMarkerDetectsWordPress(): void
    {
        $root = $this->mkproject(['wp-config.php' => '<?php // wp']);
        $this->assertSame('wordpress', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testComposerJsonAloneIsGeneric(): void
    {
        $root = $this->mkproject(['composer.json' => '{}']);
        $this->assertSame('generic', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testEmptyDirIsGeneric(): void
    {
        $root = $this->mkproject([]);
        $this->assertSame('generic', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testRegistryLoadsBundledProfiles(): void
    {
        $registry = ProfileRegistry::bundled();
        $available = $registry->listAvailable();
        $this->assertContains('laravel', $available);
        $this->assertContains('symfony', $available);
        $this->assertContains('drupal', $available);
        $this->assertContains('wordpress', $available);
        $this->assertContains('generic', $available);

        $laravel = $registry->load('laravel');
        $this->assertArrayHasKey('exclude', $laravel);
        $this->assertContains('vendor/**', $laravel['exclude']);
        $this->assertArrayNotHasKey('_description', $laravel, 'profile loader strips the human description');
    }

    public function testRegistryRejectsUnknownProfile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown profile/');
        ProfileRegistry::bundled()->load('does-not-exist');
    }

    public function testDoctrinePackageDetectsDbAwareDoctrine(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['doctrine/orm' => '^2.0']])]);
        $this->assertSame('db-aware-doctrine', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testDoctrineBundlePackageDetectsDbAwareDoctrine(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['doctrine/doctrine-bundle' => '^2.0']])]);
        $this->assertSame('db-aware-doctrine', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testCycleOrmPackageDetectsDbAwareCycle(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['cycle/orm' => '^3.0']])]);
        $this->assertSame('db-aware-cycle', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testPropelPackageDetectsDbAwarePropel(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['propel/propel' => '^2.0']])]);
        $this->assertSame('db-aware-propel', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testPropelOrmPackageDetectsDbAwarePropel(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['propelorm/propel' => '^2.0']])]);
        $this->assertSame('db-aware-propel', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testRedbeanPackageDetectsDbAwareRedbean(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['gabordemooij/redbean' => '^5.0']])]);
        $this->assertSame('db-aware-redbean', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testCakeOrmPackageDetectsDbAwareCake(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['cakephp/orm' => '^4.0']])]);
        $this->assertSame('db-aware-cake', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testMedooPackageDetectsDbAwareMedoo(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['catfan/medoo' => '^2.0']])]);
        $this->assertSame('db-aware-medoo', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testPhpActiverecordPackageDetectsDbAwarePhpactiverecord(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['php-activerecord/php-activerecord' => '^1.0']])]);
        $this->assertSame('db-aware-phpactiverecord', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testThinkormPackageDetectsDbAwareThinkorm(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['topthink/think-orm' => '^2.0']])]);
        $this->assertSame('db-aware-thinkorm', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testPredisPackageDetectsDbAwareRedis(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['predis/predis' => '^2.0']])]);
        $this->assertSame('db-aware-redis', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testCredisPackageDetectsDbAwareRedis(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['colinmollenhour/credis' => '^1.0']])]);
        $this->assertSame('db-aware-redis', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testPhpredisPackageDetectsDbAwareRedis(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['phpredis/phpredis' => '^5.0']])]);
        $this->assertSame('db-aware-redis', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testMongoDBPackageDetectsDbAwareMongodb(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['mongodb/mongodb' => '^1.0']])]);
        $this->assertSame('db-aware-mongodb', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testElasticsearchPackageDetectsDbAwareElasticsearch(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['elasticsearch/elasticsearch' => '^8.0']])]);
        $this->assertSame('db-aware-elasticsearch', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testNeo4jPhpClientPackageDetectsDbAwareNeo4j(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['laudis/neo4j-php-client' => '^2.0']])]);
        $this->assertSame('db-aware-neo4j', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testInfluxdbClientPackageDetectsDbAwareInfluxdb(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['influxdata/influxdb-client-php' => '^2.0']])]);
        $this->assertSame('db-aware-influxdb', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testCouchdbOdmPackageDetectsDbAwareCouchdb(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['doctrine/couchdb-odm' => '^1.0']])]);
        $this->assertSame('db-aware-couchdb', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testCouchbasePackageDetectsDbAwareCouchbase(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['couchbase/couchbase' => '^4.0']])]);
        $this->assertSame('db-aware-couchbase', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testIdiormPackageDetectsDbAwareIdiorm(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['j4mie/idiorm' => '^1.0']])]);
        $this->assertSame('db-aware-idiorm', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testParisPackageDetectsDbAwareIdiorm(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['paris/paris' => '^1.0']])]);
        $this->assertSame('db-aware-idiorm', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testPhalconPackageDetectsDbAwarePhalcon(): void
    {
        $root = $this->mkproject(['composer.json' => json_encode(['require' => ['phalcon/cphalcon' => '^5.0']])]);
        $this->assertSame('db-aware-phalcon', (new ProjectProfileDetector())->detect([$root]));
    }

    /** @param array<string,string> $files */
    private function mkproject(array $files): string
    {
        $root = sys_get_temp_dir() . '/phpdup-profile-' . uniqid();
        mkdir($root, 0o775, true);
        foreach ($files as $rel => $contents) {
            $abs = $root . '/' . $rel;
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0o775, true);
            }
            file_put_contents($abs, $contents);
        }
        $this->cleanup[] = $root;
        return $root;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
