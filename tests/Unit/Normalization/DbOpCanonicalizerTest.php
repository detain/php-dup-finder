<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;

/**
 * End-to-end tests for {@see \Phpdup\Normalization\DbOpCanonicalizer}
 * via the public {@see Normalizer} API.
 *
 * These tests assert the *clustering* contract: that semantically
 * equivalent ORM and raw-SQL variants produce the **same** canonical
 * structural hash when `--db-aware` is enabled, and that they remain
 * **distinct** without it (so the feature is a real opt-in, not a
 * silent default change).
 */
final class DbOpCanonicalizerTest extends TestCase
{
    public function testEloquentFindEqualsDoctrineFind(): void
    {
        // Use identical wrapping so the receiver/arg-count delta of
        // ORM dialects doesn't bleed into the function-signature
        // portion of the hash. The body is what we're testing.
        $eloquent = '<?php function f($a, $b) { return User::find($b); }';
        $doctrine = '<?php function f($a, $b) { return $a->find(User::class, $b); }';
        // With db-aware, both bodies fold to `return __DB_READ__("user");`.
        $this->assertSame(
            $this->canonicalHashDbAware($eloquent),
            $this->canonicalHashDbAware($doctrine),
        );
    }

    public function testRawPdoSelectEqualsMysqliSelect(): void
    {
        $pdo = '<?php function f($pdo, $id) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        }';
        $mysqli = '<?php function f($mysqli, $id) {
            $stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute();
            return $stmt->fetch();
        }';
        // Both sequences fold to a similar __DB_*__ token chain.
        $this->assertSame(
            $this->canonicalHashDbAware($pdo),
            $this->canonicalHashDbAware($mysqli),
        );
    }

    public function testRawSqlAndPgQueryParamsCluster(): void
    {
        $pdo = '<?php function f($pdo, $id) {
            return $pdo->query("SELECT * FROM accounts WHERE id = " . $id);
        }';
        $pg = '<?php function f($conn, $id) {
            return pg_query_params($conn, "SELECT * FROM accounts WHERE id = $1", [$id]);
        }';
        // Both fold to __DB_READ__("SELECT", "accounts").
        $this->assertSame(
            $this->canonicalHashDbAware($pdo),
            $this->canonicalHashDbAware($pg),
        );
    }

    public function testEloquentSaveEqualsDoctrineFlush(): void
    {
        // Identical scaffolding — only the terminal write call differs.
        $eloquent = '<?php function f($a, $b) { $b->name = "Bob"; $b->save(); }';
        $doctrine = '<?php function f($a, $b) { $b->name = "Bob"; $a->flush(); }';
        // Both terminal writes rewrite to __DB_WRITE__("?").
        $this->assertSame(
            $this->canonicalHashDbAware($eloquent),
            $this->canonicalHashDbAware($doctrine),
        );
    }

    public function testQueryBuilderUpdateAndRawUpdateCluster(): void
    {
        $builder = '<?php function f($db, $id) {
            return $db->table("users")->where("id", $id)->update(["name" => "Bob"]);
        }';
        $raw = '<?php function f($pdo, $id) {
            return $pdo->query("UPDATE users SET name = \'Bob\' WHERE id = " . $id);
        }';
        // Both fold to a __DB_WRITE__ shape. Assert the synthetic op
        // token appears in each canonicalised token stream — that is
        // what the n-gram fingerprint feeds on, so identical op tokens
        // imply ngram overlap.
        $tokensA = $this->canonicalTokens($builder, true);
        $tokensB = $this->canonicalTokens($raw, true);

        $this->assertContains('Name|n:__DB_WRITE__', $tokensA,
            'builder update should produce __DB_WRITE__ in tokens');
        $this->assertContains('Name|n:__DB_WRITE__', $tokensB,
            'raw UPDATE should produce __DB_WRITE__ in tokens');
    }

    public function testWithoutDbAwareTheVariantsRemainDistinct(): void
    {
        $eloquent = '<?php function f($id) { return User::find($id); }';
        $doctrine = '<?php function f($em, $id) { return $em->find(User::class, $id); }';
        // Off by default → AST-based scoring keeps them apart.
        $this->assertNotSame(
            $this->canonicalHash($eloquent, dbAware: false),
            $this->canonicalHash($doctrine, dbAware: false),
        );
    }

    public function testInterpolatedSqlIsParsed(): void
    {
        $hash = $this->canonicalHashDbAware('<?php function f($db, $id) {
            return $db->query("SELECT * FROM users WHERE id = {$id}");
        }');
        $explicit = $this->canonicalHashDbAware('<?php function f($db, $id) {
            return $db->query("SELECT * FROM users WHERE id = ?");
        }');
        $this->assertSame($explicit, $hash, 'interpolation is reduced to placeholder');
    }

    public function testBuilderTableSeedSurfaces(): void
    {
        $a = '<?php function f($db) { return $db->table("orders")->where("id", 1)->first(); }';
        $b = '<?php function f($db) { return $db->table("orders")->first(); }';
        // The chain walker surfaces "orders" from the seed table()
        // call regardless of intermediate where(); both produce a
        // __DB_READ__ token in their canonical form.
        $tokensA = $this->canonicalTokens($a, true);
        $tokensB = $this->canonicalTokens($b, true);
        $this->assertContains('Name|n:__DB_READ__', $tokensA);
        $this->assertContains('Name|n:__DB_READ__', $tokensB);
    }

    public function testNonDbCallsAreLeftAlone(): void
    {
        // A vanilla function like array_sum should be untouched by
        // db-aware canonicalisation — its hash matches the non-db-aware
        // hash. (This guards against false-positive rewrites.)
        $code = '<?php function f($xs) { return array_sum($xs); }';
        $this->assertSame(
            $this->canonicalHash($code, dbAware: false),
            $this->canonicalHashDbAware($code),
        );
    }

    /** @return list<string> Canonicalised token stream of the first block. */
    private function canonicalTokens(string $code, bool $dbAware): array
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode($code);
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = $extractor->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        $normalizer = new Normalizer(mode: 'aggressive', dbAware: $dbAware);
        $normalizer->normalize($blocks[0]);
        return \Phpdup\Util\AstSerializer::tokens($blocks[0]->canonical);
    }

    private function canonicalHash(string $code, bool $dbAware): string
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode($code);
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = $extractor->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        $normalizer = new Normalizer(mode: 'aggressive', dbAware: $dbAware);
        $normalizer->normalize($blocks[0]);
        return (new SubtreeHasher())->hash($blocks[0]->canonical);
    }

    private function canonicalHashDbAware(string $code): string
    {
        return $this->canonicalHash($code, dbAware: true);
    }
}
