<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Normalization\DbOpRegistry;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\AstSerializer;

/**
 * Integration tests for option 4 of
 * docs/plans/orm-db-semantic-dedup.md: the user-extensible DB
 * symbol equivalence registry.
 *
 * The DbOpRegistry already accepts custom method/function maps.
 * These tests verify that the wiring from `phpdup.json -> db_symbols`
 * (and from a profile JSON) all the way down to the Normalizer's
 * canonicalised AST works end-to-end.
 */
final class CustomDbSymbolsTest extends TestCase
{
    public function testCustomMethodSymbolFoldsToSyntheticOp(): void
    {
        $registry = new DbOpRegistry(customMethodOps: ['frobnicate' => DbOpRegistry::OP_QUERY]);
        $tokens = $this->canonicalTokens(
            '<?php function f($x, $y) { return $x->frobnicate($y); }',
            $registry,
        );
        $this->assertContains('Name|n:__DB_QUERY__', $tokens,
            'a method named in the custom registry must fold to its op-class synthetic call');
    }

    public function testCustomFunctionSymbolFoldsToSyntheticOp(): void
    {
        $registry = new DbOpRegistry(customFunctionOps: ['custom_db_call' => DbOpRegistry::OP_READ]);
        $tokens = $this->canonicalTokens(
            '<?php function f($x) { return custom_db_call($x); }',
            $registry,
        );
        $this->assertContains('Name|n:__DB_READ__', $tokens);
    }

    public function testConfigLoaderRoundTripsDbSymbolsFromJson(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        file_put_contents($tmp, json_encode([
            'db_aware'   => true,
            'db_symbols' => [
                'methods'   => ['myfind' => 'db.read'],
                'functions' => ['my_query' => 'db.query'],
            ],
        ]));
        try {
            $config = (new ConfigLoader())->load(paths: ['src'], configFile: $tmp);
            $this->assertSame(['myfind' => 'db.read'], $config->dbSymbolsMethods);
            $this->assertSame(['my_query' => 'db.query'], $config->dbSymbolsFunctions);
        } finally {
            @unlink($tmp);
        }
    }

    public function testConfigLoaderValidatesDbSymbolsShape(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown config key 'db_symbols.bogus'");
        (new ConfigLoader())->validate([
            'db_symbols' => ['bogus' => []],
        ]);
    }

    public function testConfigLoaderValidatesOpEnumeration(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db_symbols.methods.foo');
        (new ConfigLoader())->validate([
            'db_symbols' => ['methods' => ['foo' => 'db.unknown']],
        ]);
    }

    public function testCustomEntryOverridesStock(): void
    {
        // Prove that custom entries take precedence — flip the canonical
        // op of an existing stock entry to demonstrate.
        $registry = new DbOpRegistry(customMethodOps: ['find' => DbOpRegistry::OP_DELETE]);
        $tokens = $this->canonicalTokens(
            '<?php function f($id) { return User::find($id); }',
            $registry,
        );
        $this->assertContains('Name|n:__DB_DELETE__', $tokens,
            'custom override must replace the stock OP_READ classification');
    }

    public function testBundledLaravelDbSymbolsLoadCleanly(): void
    {
        // The bundled profiles/db-aware-laravel.json must round-trip
        // through ProfileRegistry without issues so users can reach
        // it via `--profile=db-aware-laravel`.
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-laravel', $registry->listAvailable());
        $loaded = $registry->load('db-aware-laravel');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $this->assertIsArray($loaded['db_symbols']['methods']);
    }

    public function testBundledDoctrineDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-doctrine', $registry->listAvailable());
        $loaded = $registry->load('db-aware-doctrine');
        $this->assertArrayHasKey('db_symbols', $loaded);
    }

    public function testBundledCakeDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-cake', $registry->listAvailable());
        $loaded = $registry->load('db-aware-cake');
        $this->assertArrayHasKey('db_symbols', $loaded);
    }

    public function testBundledThinkormDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-thinkorm', $registry->listAvailable());
        $loaded = $registry->load('db-aware-thinkorm');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $methods = $loaded['db_symbols']['methods'];
        // Verify some key think-orm methods are mapped
        $this->assertSame('db.read', $methods['find'] ?? null);
        $this->assertSame('db.read', $methods['select'] ?? null);
        $this->assertSame('db.write', $methods['insert'] ?? null);
        $this->assertSame('db.delete', $methods['delete'] ?? null);
    }

    public function testBundledMedooDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-medoo', $registry->listAvailable());
        $loaded = $registry->load('db-aware-medoo');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $methods = $loaded['db_symbols']['methods'];
        $this->assertSame('db.read', $methods['select'] ?? null);
        $this->assertSame('db.write', $methods['insert'] ?? null);
        $this->assertSame('db.query', $methods['query'] ?? null);
    }

    public function testBundledPropelDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-propel', $registry->listAvailable());
        $loaded = $registry->load('db-aware-propel');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $methods = $loaded['db_symbols']['methods'];
        $this->assertSame('db.read', $methods['doselect'] ?? null);
        $this->assertSame('db.write', $methods['doinsert'] ?? null);
        $this->assertSame('db.delete', $methods['dodelete'] ?? null);
    }

    public function testBundledRedbeanDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-redbean', $registry->listAvailable());
        $loaded = $registry->load('db-aware-redbean');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $methods = $loaded['db_symbols']['methods'];
        $this->assertSame('db.read', $methods['find'] ?? null);
        $this->assertSame('db.write', $methods['store'] ?? null);
        $this->assertSame('db.delete', $methods['trash'] ?? null);
    }

    public function testBundledCycleDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-cycle', $registry->listAvailable());
        $loaded = $registry->load('db-aware-cycle');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $methods = $loaded['db_symbols']['methods'];
        $this->assertSame('db.read', $methods['find'] ?? null);
        $this->assertSame('db.write', $methods['persist'] ?? null);
    }

    public function testBundledPhpActiveRecordDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-phpactiverecord', $registry->listAvailable());
        $loaded = $registry->load('db-aware-phpactiverecord');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $methods = $loaded['db_symbols']['methods'];
        $this->assertSame('db.read', $methods['find'] ?? null);
        $this->assertSame('db.read', $methods['first'] ?? null);
        $this->assertSame('db.write', $methods['create'] ?? null);
        $this->assertSame('db.delete', $methods['destroy'] ?? null);
    }

    public function testBundledMyadminDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-myadmin', $registry->listAvailable());
        $loaded = $registry->load('db-aware-myadmin');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $methods = $loaded['db_symbols']['methods'];
        // Verify key MyAdmin db_abstraction methods
        $this->assertSame('db.read', $methods['next_record'] ?? null);
        $this->assertSame('db.read', $methods['qr'] ?? null);
        $this->assertSame('db.query', $methods['query'] ?? null);
        $this->assertSame('db.write', $methods['getLastInsertId'] ?? null);
        $this->assertSame('db.execute', $methods['prepare'] ?? null);
    }

    public function testBundledMyadminOrmDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-myadmin-orm', $registry->listAvailable());
        $loaded = $registry->load('db-aware-myadmin-orm');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $methods = $loaded['db_symbols']['methods'];
        // Verify key MyAdmin ORM methods
        $this->assertSame('db.read', $methods['find'] ?? null);
        $this->assertSame('db.read', $methods['load'] ?? null);
        $this->assertSame('db.write', $methods['save'] ?? null);
        $this->assertSame('db.delete', $methods['delete'] ?? null);
    }

    public function testBundledCodeigniterDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-codeigniter', $registry->listAvailable());
        $loaded = $registry->load('db-aware-codeigniter');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $methods = $loaded['db_symbols']['methods'];
        // Verify key CodeIgniter DB methods
        $this->assertSame('db.read', $methods['get'] ?? null);
        $this->assertSame('db.write', $methods['insert'] ?? null);
        $this->assertSame('db.write', $methods['update'] ?? null);
        $this->assertSame('db.delete', $methods['delete'] ?? null);
    }

    public function testBundledLaminasDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-laminas', $registry->listAvailable());
        $loaded = $registry->load('db-aware-laminas');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $methods = $loaded['db_symbols']['methods'];
        // Verify key Laminas DB methods
        $this->assertSame('db.read', $methods['select'] ?? null);
        $this->assertSame('db.read', $methods['fetchAll'] ?? null);
        $this->assertSame('db.read', $methods['fetchOne'] ?? null);
        $this->assertSame('db.write', $methods['insert'] ?? null);
        $this->assertSame('db.read', $methods['query'] ?? null);
    }

    public function testBundledYiiDbSymbolsLoadCleanly(): void
    {
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        $this->assertContains('db-aware-yii', $registry->listAvailable());
        $loaded = $registry->load('db-aware-yii');
        $this->assertArrayHasKey('db_symbols', $loaded);
        $methods = $loaded['db_symbols']['methods'];
        // Verify key Yii ActiveRecord methods
        $this->assertSame('db.read', $methods['find'] ?? null);
        $this->assertSame('db.read', $methods['findOne'] ?? null);
        $this->assertSame('db.read', $methods['findAll'] ?? null);
        $this->assertSame('db.write', $methods['save'] ?? null);
        $this->assertSame('db.delete', $methods['delete'] ?? null);
    }

    public function testNewDbAwareProfilesCoverKeyCrudOperations(): void
    {
        // Verify all new profiles map the four basic CRUD operations
        $profiles = ['db-aware-codeigniter', 'db-aware-laminas', 'db-aware-yii',
                     'db-aware-thinkorm', 'db-aware-medoo', 'db-aware-propel',
                     'db-aware-redbean', 'db-aware-cycle', 'db-aware-phpactiverecord',
                     'db-aware-myadmin-orm'];
        $registry = \Phpdup\Cli\ProfileRegistry::bundled();
        foreach ($profiles as $name) {
            $loaded = $registry->load($name);
            $methods = $loaded['db_symbols']['methods'];
            $hasRead = in_array('db.read', $methods, true);
            $hasWrite = in_array('db.write', $methods, true);
            $hasDelete = in_array('db.delete', $methods, true);
            $this->assertTrue($hasRead && $hasWrite && $hasDelete,
                "Profile $name must cover db.read, db.write, and db.delete operations");
        }
    }

    /** @return list<string> */
    private function canonicalTokens(string $code, DbOpRegistry $registry): array
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode($code);
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = $extractor->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        $normalizer = new Normalizer(
            mode: 'aggressive',
            dbAware: true,
            dbOpRegistry: $registry,
        );
        $normalizer->normalize($blocks[0]);
        return AstSerializer::tokens($blocks[0]->canonical);
    }
}
