<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\ProfileRegistry;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Normalization\DbOpRegistry;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\AstSerializer;

final class DbOpRegistryTest extends TestCase
{
    public function testEloquentReadVerbs(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_READ, $r->lookupMethod('find'));
        $this->assertSame(DbOpRegistry::OP_READ, $r->lookupMethod('findOrFail'));
        $this->assertSame(DbOpRegistry::OP_READ, $r->lookupMethod('first'));
        $this->assertSame(DbOpRegistry::OP_READ, $r->lookupMethod('all'));
        $this->assertSame(DbOpRegistry::OP_READ, $r->lookupMethod('get'));
    }

    public function testDoctrineWriteVerbs(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('persist'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('flush'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('save'));
    }

    public function testDeletes(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_DELETE, $r->lookupMethod('delete'));
        $this->assertSame(DbOpRegistry::OP_DELETE, $r->lookupMethod('destroy'));
        $this->assertSame(DbOpRegistry::OP_DELETE, $r->lookupMethod('remove'));
    }

    public function testGenericQueryAndExecute(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupMethod('query'));
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $r->lookupMethod('execute'));
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $r->lookupMethod('prepare'));
    }

    public function testMysqliFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('mysqli_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('mysqli_fetch_assoc'));
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $r->lookupFunction('mysqli_stmt_execute'));
    }

    public function testPostgresFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('pg_query'));
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('pg_query_params'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('pg_fetch_assoc'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupFunction('pg_insert'));
        $this->assertSame(DbOpRegistry::OP_DELETE, $r->lookupFunction('pg_delete'));
    }

    public function testCaseInsensitiveLookup(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame($r->lookupMethod('find'),  $r->lookupMethod('FIND'));
        $this->assertSame($r->lookupMethod('save'),  $r->lookupMethod('Save'));
        $this->assertSame($r->lookupFunction('pg_query'), $r->lookupFunction('PG_QUERY'));
    }

    public function testReturnsNullForUnknownNames(): void
    {
        $r = DbOpRegistry::default();
        $this->assertNull($r->lookupMethod('frobnicate'));
        $this->assertNull($r->lookupFunction('strlen'));
    }

    public function testCustomMethodOverridesStock(): void
    {
        $r = new DbOpRegistry(customMethodOps: ['find' => DbOpRegistry::OP_WRITE]);
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('find'));
    }

    public function testCustomFunctionExtendsStock(): void
    {
        $r = new DbOpRegistry(customFunctionOps: ['my_db_query' => DbOpRegistry::OP_QUERY]);
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('my_db_query'));
        // Stock entries still resolve.
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('mysqli_query'));
    }

    public function testBuilderIntermediatesAreNotMapped(): void
    {
        // Verify that intermediate query-builder calls (which return
        // a Builder, not a result) are intentionally NOT in the
        // registry — the *terminal* call carries the canonical op.
        $r = DbOpRegistry::default();
        $this->assertNull($r->lookupMethod('where'));
        $this->assertNull($r->lookupMethod('whereIn'));
        $this->assertNull($r->lookupMethod('orderBy'));
        $this->assertNull($r->lookupMethod('limit'));
        $this->assertNull($r->lookupMethod('with'));
        $this->assertNull($r->lookupMethod('join'));
    }

    public function testAsyncTransactionMethods(): void
    {
        $r = DbOpRegistry::default();
        // Transaction methods
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('beginTransaction'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('commit'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('rollback'));
    }

    public function testAsyncAuxiliaryMethods(): void
    {
        $r = DbOpRegistry::default();
        // Async auxiliary methods
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupMethod('affectedRows'));
        $this->assertSame(DbOpRegistry::OP_WRITE, $r->lookupMethod('insertId'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupMethod('count'));
    }

    public function testAsyncAmphpMySqlFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('amysql_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('amysql_fetch_assoc'));
    }

    public function testAsyncReactMySqlFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('react_mysql_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('react_mysql_fetch_assoc'));
    }

    public function testAsyncSwooleCoroutineMySqlFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('swoole_mysql_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('swoole_mysql_fetch_assoc'));
    }

    public function testAsyncWorkermanMySqlFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('workerman_mysql_query'));
    }

    public function testAsyncAmphpPostgresFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('apg_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('apg_fetch_assoc'));
    }

    public function testAsyncReactPostgresFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('react_pg_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('react_pg_fetch_assoc'));
    }

    public function testAsyncSwooleCoroutinePostgresFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('swoole_postgres_query'));
    }

    public function testOracleOci8Functions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $r->lookupFunction('oci_parse'));
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $r->lookupFunction('oci_execute'));
        $this->assertSame(DbOpRegistry::OP_READ,    $r->lookupFunction('oci_fetch'));
        $this->assertSame(DbOpRegistry::OP_WRITE,   $r->lookupFunction('oci_commit'));
        $this->assertSame(DbOpRegistry::OP_WRITE,   $r->lookupFunction('oci_rollback'));
    }

    public function testCassandraPhpcassaFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_QUERY, $r->lookupFunction('phpcassa_query'));
        $this->assertSame(DbOpRegistry::OP_READ,  $r->lookupFunction('phpcassa_fetch'));
    }

    public function testLevelDbFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_READ,    $r->lookupFunction('leveldb_get'));
        $this->assertSame(DbOpRegistry::OP_WRITE,   $r->lookupFunction('leveldb_put'));
        $this->assertSame(DbOpRegistry::OP_DELETE,  $r->lookupFunction('leveldb_delete'));
    }

    public function testMemcachedFunctions(): void
    {
        $r = DbOpRegistry::default();
        $this->assertSame(DbOpRegistry::OP_READ,    $r->lookupFunction('memcached_get'));
        $this->assertSame(DbOpRegistry::OP_WRITE,   $r->lookupFunction('memcached_set'));
        $this->assertSame(DbOpRegistry::OP_DELETE,  $r->lookupFunction('memcached_delete'));
    }

    // ---------------------------------------------------------------
    // Registry entry shape (engine-003 half B, r1 L-7): the generic
    // name tier is derived from ENTRY_FLAG_GENERIC on the METHOD_OPS
    // entries themselves — there is no parallel list to drift.
    // ---------------------------------------------------------------

    public function testGenericNameTierDerivesFromEntryFlags(): void
    {
        $this->assertSame(['all', 'get', 'save'], DbOpRegistry::default()->genericNameTier());
    }

    public function testGateAndDerivedTierAgreeForEveryProbeName(): void
    {
        // The drift guard: the predicate and the surfaced list are two
        // reads of ONE source. Any stock or unknown name must answer
        // identically through both, whatever case it arrives in.
        $r = DbOpRegistry::default();
        $tier = $r->genericNameTier();
        $probes = [
            'get', 'GET', 'Get', 'all', 'ALL', 'save', 'Save',
            'find', 'update', 'delete', 'query', 'exec', 'flush',
            'create', 'count', 'memcached_get', 'frobnicate',
        ];
        foreach ($probes as $name) {
            $inTier = in_array(strtolower($name), $tier, true);
            $this->assertSame(
                $inTier,
                $r->requiresReceiverEvidence($name),
                "gate/tier disagreement for '{$name}' — the tier is derived, it may not diverge",
            );
        }
    }

    public function testCustomMappingsNeverAlterTheDerivedTier(): void
    {
        // Informative reclassification (`save` as a read) exempts the
        // gate (pinned end-to-end in CustomDbSymbolsTest) but must not
        // add or remove members of the derived tier, and custom names
        // never land in it regardless of op.
        $r = new DbOpRegistry(
            customMethodOps: ['save' => DbOpRegistry::OP_READ, 'frobnicate' => DbOpRegistry::OP_QUERY],
        );
        $this->assertSame(['all', 'get', 'save'], $r->genericNameTier());
        $this->assertFalse($r->requiresReceiverEvidence('frobnicate'));
        $this->assertFalse($r->requiresReceiverEvidence('save'));
        $this->assertTrue($r->requiresReceiverEvidence('get'));
    }

    public function testFlattenedOpsLeakNoEntryListShape(): void
    {
        // METHOD_OPS stores [op, ...flags] lists; the public lookup
        // surface must hand out the scalar op only — including for the
        // flagged tier names.
        $r = DbOpRegistry::default();
        foreach (['get' => DbOpRegistry::OP_READ, 'all' => DbOpRegistry::OP_READ, 'save' => DbOpRegistry::OP_WRITE] as $name => $op) {
            $this->assertSame($op, $r->lookupMethod($name));
        }
    }

    // ---------------------------------------------------------------
    // Pack hygiene (php-dup-finder-once-125): the bundled db-aware-*
    // symbol packs share method names — a Laravel tree and a bare
    // Illuminate tree issue the same ->delete() call — so any key
    // present in two packs must classify identically in both, the
    // delete-ish family must match the stock dispatch table, no pack
    // may repeat a JSON key (the later one silently wins), and the
    // pack census the --db-aware path reads off disk must stay
    // unchanged by a hygiene fix.
    // ---------------------------------------------------------------

    /** Names that anchor the delete-ish family the finding calls out. */
    private const DELETE_FAMILY_KEYS = ['delete', 'truncate', 'statement', 'affectingstatement'];

    public function testShippedPackJsonParsesWithUniqueKeys(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/profiles/*.json');
        $this->assertIsArray($files);
        $this->assertNotCount(0, $files, 'profiles/ dir census — a glob failure would vacuate this guard');
        foreach ($files as $file) {
            $raw = (string) file_get_contents($file);
            try {
                json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->fail(basename($file) . ' is not valid JSON: ' . $e->getMessage());
            }
            $dups = self::duplicateJsonKeys($raw);
            $this->assertSame(
                [],
                $dups,
                basename($file) . ' repeats JSON key(s) ' . implode(', ', $dups)
                    . ' — json_decode lets the last one silently win',
            );
        }
    }

    public function testDeleteFamilyAgreesBetweenLaravelFamilyPacks(): void
    {
        $packs = [
            'db-aware-laravel'    => self::packMethods('db-aware-laravel'),
            'db-aware-illuminate' => self::packMethods('db-aware-illuminate'),
            'db-aware-thinkorm'   => self::packMethods('db-aware-thinkorm'),
        ];

        // Shared keys conflict-free: any method name shipped by two of
        // the packs carries the same canonical op in both, so the token
        // of an identical call never depends on which pack loaded.
        $checked = 0;
        $names = array_keys($packs);
        for ($a = 0; $a < count($names); $a++) {
            for ($b = $a + 1; $b < count($names); $b++) {
                foreach (array_intersect_key($packs[$names[$a]], $packs[$names[$b]]) as $key => $opA) {
                    $this->assertSame(
                        $opA,
                        $packs[$names[$b]][$key],
                        "shared key '$key' disagrees between {$names[$a]} and {$names[$b]}",
                    );
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(0, $checked, 'the trio shares no keys at all — the agreement loop vacuated');

        // The delete-ish family is not merely self-consistent: every
        // family key that the stock table also classifies must match
        // it (the finding's "heretic vs DbOpRegistry" half). Keys the
        // stock table does not know (`affectingstatement`) are pinned
        // by the explicit intent asserts below plus the cross-pack
        // agreement above.
        $stock = DbOpRegistry::default();
        foreach ($packs as $packName => $methods) {
            foreach (self::DELETE_FAMILY_KEYS as $key) {
                if (
                    !array_key_exists($key, $methods)
                    || $stock->lookupMethod($key) === null
                ) {
                    continue;
                }
                $this->assertSame(
                    $stock->lookupMethod($key),
                    $methods[$key],
                    "$packName.$key must equal the stock op (heretic packs were once-125)",
                );
            }
        }
        // Explicit intent, not just mutual agreement:
        $this->assertSame(DbOpRegistry::OP_DELETE, $packs['db-aware-laravel']['delete']);
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $packs['db-aware-laravel']['statement']);
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $packs['db-aware-laravel']['affectingstatement']);
        $this->assertSame(DbOpRegistry::OP_DELETE, $packs['db-aware-illuminate']['delete']);
        $this->assertSame(DbOpRegistry::OP_DELETE, $packs['db-aware-illuminate']['truncate']);
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $packs['db-aware-illuminate']['statement']);
        $this->assertSame(DbOpRegistry::OP_EXECUTE, $packs['db-aware-illuminate']['affectingstatement']);
    }

    public function testSameCallFoldsToSameDbOpTokenUnderBothLaravelPacks(): void
    {
        // The acceptance fixture: ONE code sample, normalised twice —
        // once under a registry loaded from the Laravel pack, once from
        // the Illuminate pack — must yield the identical canonical
        // token stream, and that stream must carry the stock op tag.
        $laravel    = new DbOpRegistry(customMethodOps: self::packMethods('db-aware-laravel'));
        $illuminate = new DbOpRegistry(customMethodOps: self::packMethods('db-aware-illuminate'));

        $fixtures = [
            // No SQL-literal first arg on purpose: a literal SQL
            // string would let SqlTableExtractor's verb promote the
            // synthetic and mask the pack's own name→op mapping
            // (DbOpCanonicalizer::rewriteMethodCall). With a variable
            // arg the registry op IS the token, which is what this
            // pack-hygiene guard must observe.
            ['<?php function s100d($q) { return $q->delete(); }',                              '__DB_DELETE__'],
            ['<?php function s100s($db, $sql) { return $db->statement($sql); }',              '__DB_EXECUTE__'],
            ['<?php function s100a($db, $sql) { return $db->affectingStatement($sql); }',     '__DB_EXECUTE__'],
        ];
        foreach ($fixtures as [$code, $expectedSynthetic]) {
            $tokensL = $this->canonicalTokens($code, $laravel);
            $tokensI = $this->canonicalTokens($code, $illuminate);
            $this->assertSame(
                $tokensL,
                $tokensI,
                "pack-dependent canonicalisation — same call, different op tags: $code",
            );
            $this->assertContains('Name|n:' . $expectedSynthetic, $tokensL, $code);
            $this->assertContains('Name|n:' . $expectedSynthetic, $tokensI, $code);
        }
    }

    public function testDbAwarePackCensusAndHouseShapeUnchanged(): void
    {
        // --db-aware/profile loading reads the packs off disk; a
        // hygiene fix must not add, drop, or resize any pack.
        $files = glob(dirname(__DIR__, 3) . '/profiles/db-aware-*.json');
        $this->assertIsArray($files);
        $this->assertCount(29, $files, 'db-aware pack census on disk');

        $ops = [
            DbOpRegistry::OP_READ, DbOpRegistry::OP_WRITE, DbOpRegistry::OP_DELETE,
            DbOpRegistry::OP_EXECUTE, DbOpRegistry::OP_QUERY,
        ];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($data);
            $methods = $data['db_symbols']['methods'] ?? [];
            $this->assertNotSame([], $methods, basename($file) . ' ships no db_symbols.methods');
            foreach ($methods + ($data['db_symbols']['functions'] ?? []) as $key => $op) {
                $this->assertTrue(
                    in_array($op, $ops, true),
                    basename($file) . ": value for '$key' is outside the db.* op enum",
                );
            }
        }

        // Decoded-entry census of the three touched packs, unchanged
        // (duplicate keys collapse before decode, so deduping thinkorm
        // moves nothing here — proof the fix was value-preserving).
        $this->assertCount(22, self::packMethods('db-aware-illuminate'));
        $this->assertCount(36, self::packMethods('db-aware-laravel'));
        $this->assertCount(59, self::packMethods('db-aware-thinkorm'));

        // House shape (AGENTS.md) for the laravel-family trio: keys are
        // lower-cased method names.
        foreach (['db-aware-laravel', 'db-aware-illuminate', 'db-aware-thinkorm'] as $packName) {
            foreach (self::packMethods($packName) as $key => $op) {
                $this->assertSame(strtolower((string) $key), $key, "$packName: key '$key' is not lower-cased");
            }
        }
    }

    /**
     * Load a bundled pack through the production loader
     * ({@see ProfileRegistry}) and return its method map.
     *
     * @return array<string,string>
     */
    private static function packMethods(string $name): array
    {
        $data    = ProfileRegistry::bundled()->load($name);
        $methods = $data['db_symbols']['methods'] ?? [];
        self::assertIsArray($methods, "$name: db_symbols.methods is not an object");
        /** @var array<string,string> $methods */
        return $methods;
    }

    /**
     * Minimal JSON key scanner: returns every object key that appears
     * twice inside the SAME object scope. json_decode cannot report
     * this (the last duplicate silently wins), so duplicate detection
     * runs on the raw bytes — strings (and every colon inside them)
     * are consumed whole, and only a quoted string immediately
     * followed by ':' inside an object counts as a key.
     *
     * @return list<string>
     */
    private static function duplicateJsonKeys(string $raw): array
    {
        $dups   = [];
        /** @var list<array<string,true>|null> $stack null marks array scopes */
        $stack  = [];
        $len    = strlen($raw);
        $i      = 0;
        while ($i < $len) {
            $c = $raw[$i];
            if ($c === '"') {
                $j   = $i + 1;
                $str = '';
                $esc = false;
                while ($j < $len) {
                    $d = $raw[$j];
                    if ($esc) {
                        $str .= $d;
                        $esc = false;
                        $j++;
                        continue;
                    }
                    if ($d === '\\') {
                        $str .= $d;
                        $esc = true;
                        $j++;
                        continue;
                    }
                    if ($d === '"') {
                        break;
                    }
                    $str .= $d;
                    $j++;
                }
                // Look past the closing quote: whitespace, then a colon
                // means this string is an object key.
                $k = $j + 1;
                while ($k < $len && ctype_space($raw[$k])) {
                    $k++;
                }
                $top = $stack === [] ? null : count($stack) - 1;
                if ($top !== null && $k < $len && $raw[$k] === ':' && $stack[$top] !== null) {
                    if (isset($stack[$top][$str])) {
                        $dups[] = $str;
                    } else {
                        $stack[$top][$str] = true;
                    }
                }
                $i = $j + 1;
                continue;
            }
            if ($c === '{') {
                $stack[] = [];
                $i++;
                continue;
            }
            if ($c === '[') {
                $stack[] = null;
                $i++;
                continue;
            }
            if ($c === '}' || $c === ']') {
                array_pop($stack);
                $i++;
                continue;
            }
            $i++;
        }
        return $dups;
    }

    /**
     * @return list<string> canonical token stream of the first block
     */
    private function canonicalTokens(string $code, DbOpRegistry $registry): array
    {
        $parser    = new AstParser();
        $stmts     = $parser->parseCode($code);
        $extractor = new BlockExtractor(minSize: 1);
        $blocks    = $extractor->extract('test.php', $stmts);
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
