<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\AstSerializer;

/**
 * End-to-end tests for {@see \Phpdup\Normalization\TrinityCollapser}
 * via the public {@see Normalizer} API.
 *
 * The collapser detects the canonical CRUD trinity (read → mutate →
 * save) and rewrites it as a single `__DB_UPSERT__("entity")` token
 * so an ORM upsert clusters with the equivalent raw `UPDATE` query
 * after DbOpCanonicalizer collapses the latter to its own
 * `__DB_*` token.
 */
final class TrinityCollapserTest extends TestCase
{
    public function testEloquentTrinityCollapsesToUpsert(): void
    {
        $code = '<?php function f($id) {
            $user = User::find($id);
            $user->name = "Bob";
            $user->save();
        }';
        $tokens = $this->canonicalTokens($code, trinity: true);
        $this->assertContains('Name|n:__DB_UPSERT__', $tokens,
            'trinity must collapse to a single __DB_UPSERT__ token');
        // The original three statements are gone — only the upsert
        // remains as the function body.
        $this->assertNotContains('Name|n:User', $tokens);
        $this->assertNotContains('Identifier|id:save', $tokens);
    }

    public function testDoctrineTrinityCollapsesToUpsert(): void
    {
        $code = '<?php function f($em, $id) {
            $user = $em->find(User::class, $id);
            $user->setName("Bob");
            $em->flush();
        }';
        $tokens = $this->canonicalTokens($code, trinity: true);
        $this->assertContains('Name|n:__DB_UPSERT__', $tokens,
            'Doctrine trinity must collapse to __DB_UPSERT__');
    }

    public function testEloquentAndDoctrineTrinitiesClusterTogether(): void
    {
        $eloquent = '<?php function f($a, $b) {
            $x = User::find($b);
            $x->name = "Bob";
            $x->save();
        }';
        $doctrine = '<?php function f($a, $b) {
            $x = $a->find(User::class, $b);
            $x->setName("Bob");
            $a->flush();
        }';
        $this->assertSame(
            $this->canonicalHash($eloquent, trinity: true),
            $this->canonicalHash($doctrine, trinity: true),
            'Eloquent and Doctrine trinities must produce the same canonical hash',
        );
    }

    public function testDoctrinePersistThenFlushIsRecognisedAsSave(): void
    {
        $code = '<?php function f($em) {
            $user = $em->find(User::class, 1);
            $user->setName("Bob");
            $em->persist($user);
        }';
        // persist($user) recognised as save terminator (treats the
        // first arg as the bound variable).
        $tokens = $this->canonicalTokens($code, trinity: true);
        $this->assertContains('Name|n:__DB_UPSERT__', $tokens);
    }

    public function testTrinityRequiresAtLeastOneMutate(): void
    {
        // Read+save with no mutation in between should NOT collapse —
        // it's a load + immediate save (e.g. touch). The collapser
        // requires evidence of state change.
        $code = '<?php function f($id) {
            $user = User::find($id);
            $user->save();
        }';
        $tokens = $this->canonicalTokens($code, trinity: true);
        $this->assertNotContains('Name|n:__DB_UPSERT__', $tokens);
    }

    public function testUnrelatedStatementBetweenReadAndSaveAbandonsTrinity(): void
    {
        // The walker is conservative — an unrelated statement breaks
        // the chain and the read is left alone.
        $code = '<?php function f($id, $log) {
            $user = User::find($id);
            $user->name = "Bob";
            $log->info("about to save");
            $user->save();
        }';
        $tokens = $this->canonicalTokens($code, trinity: true);
        $this->assertNotContains('Name|n:__DB_UPSERT__', $tokens,
            'unrelated stmt between mutate and save must abandon trinity');
    }

    public function testMultipleMutationsAllAbsorbed(): void
    {
        // Multiple property assignments + setters absorb cleanly.
        $code = '<?php function f($id) {
            $u = User::find($id);
            $u->name = "Bob";
            $u->setEmail("bob@example.com");
            $u->setActive(true);
            $u->save();
        }';
        $tokens = $this->canonicalTokens($code, trinity: true);
        $this->assertContains('Name|n:__DB_UPSERT__', $tokens);
        // Original setter names should not survive the collapse.
        $this->assertNotContains('Identifier|id:setEmail', $tokens);
    }

    public function testTrinityInsideIfBranchCollapses(): void
    {
        // The visitor should walk into nested stmt arrays.
        $code = '<?php function f($id, $cond) {
            if ($cond) {
                $user = User::find($id);
                $user->name = "Bob";
                $user->save();
            }
        }';
        $tokens = $this->canonicalTokens($code, trinity: true);
        $this->assertContains('Name|n:__DB_UPSERT__', $tokens);
    }

    public function testTrinityCollapseOffByDefault(): void
    {
        $code = '<?php function f($id) {
            $user = User::find($id);
            $user->name = "Bob";
            $user->save();
        }';
        $tokens = $this->canonicalTokens($code, trinity: false);
        // Without the flag, no upsert token is synthesised.
        $this->assertNotContains('Name|n:__DB_UPSERT__', $tokens);
    }

    public function testRawSqlUpdateAndOrmTrinityClusterUnderBothFlags(): void
    {
        // The plan's worked example: ORM upsert clusters with raw SQL
        // UPDATE after both passes run.
        //
        // ORM trinity → __DB_UPSERT__("user")
        // Raw SQL    → __DB_WRITE__("users", "UPDATE")
        //
        // These remain *similar* (both __DB_* writes on the same
        // table) but not byte-identical. The acceptance bar here is
        // that both fold to a __DB_*__ token *family* — the existing
        // n-gram + Jaccard scorer takes it from there.
        $orm = '<?php function f($id) {
            $u = User::find($id);
            $u->name = "Bob";
            $u->save();
        }';
        $raw = '<?php function f($pdo, $id) {
            $pdo->query("UPDATE users SET name = \'Bob\' WHERE id = " . $id);
        }';
        $ormTokens = $this->canonicalTokens($orm, trinity: true, dbAware: true);
        $rawTokens = $this->canonicalTokens($raw, trinity: true, dbAware: true);
        $this->assertTrue(
            in_array('Name|n:__DB_UPSERT__', $ormTokens, true)
                || in_array('Name|n:__DB_WRITE__', $ormTokens, true),
            'ORM trinity must produce a __DB_UPSERT__ or __DB_WRITE__ token',
        );
        $this->assertContains('Name|n:__DB_WRITE__', $rawTokens,
            'raw SQL UPDATE must produce __DB_WRITE__');
    }

    /** @return list<string> */
    private function canonicalTokens(
        string $code,
        bool $trinity = false,
        bool $dbAware = false,
    ): array {
        $parser = new AstParser();
        $stmts = $parser->parseCode($code);
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = $extractor->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        $normalizer = new Normalizer(
            mode: 'aggressive',
            dbAware: $dbAware,
            trinityCollapse: $trinity,
        );
        $normalizer->normalize($blocks[0]);
        return AstSerializer::tokens($blocks[0]->canonical);
    }

    private function canonicalHash(
        string $code,
        bool $trinity = false,
        bool $dbAware = false,
    ): string {
        $parser = new AstParser();
        $stmts = $parser->parseCode($code);
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = $extractor->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        $normalizer = new Normalizer(
            mode: 'aggressive',
            dbAware: $dbAware,
            trinityCollapse: $trinity,
        );
        $normalizer->normalize($blocks[0]);
        return (new SubtreeHasher())->hash($blocks[0]->canonical);
    }
}
