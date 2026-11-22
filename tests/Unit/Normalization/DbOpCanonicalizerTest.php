<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PHPUnit\Framework\TestCase;
use PhpParser\Node;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\DbOpCanonicalizer;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Semantic\DbOperationTagger;
use Phpdup\Util\AstSerializer;

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
        // The Eloquent receiver `$model` carries DB name evidence, so
        // the generic-tier `save` folds; `flush` is not generic and
        // folds on its name alone. The evidence receiver is a declared
        // parameter on both snippets (L-3: no free variables, arity
        // aligned so the signature portion of the hash stays identical).
        $eloquent = '<?php function f($a, $b, $model) { $b->name = "Bob"; $model->save(); }';
        $doctrine = '<?php function f($a, $b, $model) { $b->name = "Bob"; $a->flush(); }';
        // Both terminal writes rewrite to __DB_WRITE__("?").
        $this->assertSame(
            $this->canonicalHashDbAware($eloquent),
            $this->canonicalHashDbAware($doctrine),
        );
    }

    public function testGenericGetterWithoutReceiverEvidenceIsNotFolded(): void
    {
        // engine-003 repro: `$this->store->get($k)` vs
        // `$this->logger->get($k)` — a cache store and a logger, no
        // database anywhere. Under pure-name folding both became
        // identical `__DB_READ__` synthetics (EXACT "DB clones").
        // With the receiver-evidence gate neither folds: no synthetic
        // token appears and the db-aware stream is byte-identical to
        // the untouched non-db-aware one.
        $store = '<?php function f($k) { return $this->store->get($k); }';
        $logger = '<?php function f($k) { return $this->logger->get($k); }';
        foreach (['store' => $store, 'logger' => $logger] as $label => $code) {
            $tokens = $this->canonicalTokens($code, true);
            $this->assertNotContains('Name|n:__DB_READ__', $tokens,
                "non-DB receiver `$label` must not fold a generic get() into a DB synthetic");
            $this->assertSame(
                $this->canonicalTokens($code, false),
                $tokens,
                "db-aware output for `$label` must equal the untouched path",
            );
        }
        // And the un-folded pair no longer shares the hash class of a
        // genuine, evidence-bearing DB read.
        $genuine = '<?php function f($db, $k) { return $db->get($k); }';
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens($genuine, true));
        $this->assertNotSame(
            $this->canonicalHash($store, dbAware: true),
            $this->canonicalHash($genuine, dbAware: true),
            'a store get() must not be an EXACT clone of a DB get()',
        );
    }

    public function testBareSaveWithoutReceiverEvidenceIsNotFolded(): void
    {
        // `$b->save()` on a plain parameter carries no DB signal —
        // the block stays on the untouched path.
        $code = '<?php function f($a, $b) { $b->name = "Bob"; $b->save(); }';
        $this->assertNotContains('Name|n:__DB_WRITE__', $this->canonicalTokens($code, true));
        $this->assertSame(
            $this->canonicalHash($code, dbAware: false),
            $this->canonicalHash($code, dbAware: true),
        );
    }

    public function testReceiverNameEvidenceFoldsGenericTier(): void
    {
        // Variables/properties named with DB words are evidence: the
        // generic verbs fold exactly as they always did on such
        // receivers.
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f($db, $k) { return $db->get($k); }', true,
        ));
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f($repository) { return $repository->all(); }', true,
        ));
        $this->assertContains('Name|n:__DB_WRITE__', $this->canonicalTokens(
            '<?php function f($entity) { return $entity->save(); }', true,
        ));
    }

    public function testBuilderChainSeedIsReceiverEvidence(): void
    {
        // Structural evidence, strictly: only a real table()/from()/
        // into() seed literal in the chain proves the builder. This
        // receiver carries no DB name at all — the seed is what
        // carries the fold (L-1: pinned on a non-evident receiver so
        // the name arm can't satisfy it silently).
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f($x) { return $x->table("users")->where("id", 1)->get(); }', true,
        ));
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f() { return DB::table("users")->get(); }', true,
        ));
        // An evidence-bearing class seed (`Model` is a DB word) folds.
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f() { return Model::where("active", 1)->get(); }', true,
        ));
        // Negative pins (engine-003 F-3): before the strict probe any
        // static-rooted chain counted as structural evidence, so these
        // two unrelated non-DB chains collapsed to one another AND to
        // the bug's fabricated EXACT hash. Neither may fold now.
        $this->assertNotContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f($k) { return Cache::tags("x")->get($k); }', true,
        ));
        $this->assertNotContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f($k) { return Store::make("x")->get($k); }', true,
        ));
        // Plain domain-model seeds (`User::where(…)->get()`) are
        // refused by the same rule; the AST alone cannot tell a model
        // class from a facade. Admitting them is the constructor
        // evidence seam (pinned in
        // testPlainModelStaticFoldsWhenClassAdmittedViaSeam); a config
        // bucket feeding it composer class signals stays a booked
        // follow-up row in the S06b impl report.
    }

    public function testSeedLiteralClustersWithRawSqlReadOfSamePayload(): void
    {
        // The builder-seeded read and the same-payload raw-SQL read
        // both canonicalise to a __DB_READ__ synthetic — the shared
        // op token is what the n-gram fingerprint feeds on, so
        // genuine DB clones stay in the same family through the gate
        // (L-2: the claim the old comment made, now actually pinned).
        $builder = '<?php function f($x) { return $x->table("users")->where("id", 1)->get(); }';
        $raw = '<?php function f($pdo, $id) { return $pdo->query("SELECT * FROM users WHERE id = " . $id); }';
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens($builder, true));
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens($raw, true));
    }

    public function testStaticTierVerbsCarryTheirOwnEvidenceArms(): void
    {
        // engine-003 F-2: the tier verbs on a static call consult the
        // same predicate, with the class' last segment as name
        // candidate. Evidence-bearing classes fold (`Model::all()` —
        // `model` is a DB word; `Repository::get($k)` likewise)…
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f() { return Model::all(); }', true,
        ));
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f($k) { return Repository::get($k); }', true,
        ));
        // …while the framework facades that are *not* databases —
        // the ecosystem's most-called `get()` — refuse to fold. Before
        // this cure all three fabricated one EXACT clone class
        // (hash ebe718e3, the bug's own pre-fix hash).
        foreach (['Cache', 'Config', 'Session'] as $facade) {
            $this->assertNotContains('Name|n:__DB_READ__', $this->canonicalTokens(
                "<?php function f(\$k) { return {$facade}::get(\$k); }", true,
            ), "{$facade}::get() must not fold without class evidence");
        }
        // `User::all()` was pinned as folding here pre-cure; it now
        // refuses for the same reason the facades do — the AST alone
        // cannot tell a model class from a facade. It folds once `user`
        // is admitted via the evidence seam (see
        // testPlainModelStaticFoldsWhenClassAdmittedViaSeam); config
        // wiring into that seam is a booked follow-up (S06b report).
        $this->assertNotContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f() { return User::all(); }', true,
        ));
        // Non-tier static verbs are untouched by the gate:
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f($id) { return User::find($id); }', true,
        ));
    }

    public function testCustomReceiverEvidenceNamesExtendTheSet(): void
    {
        // Extension seam: a caller that *knows* `$store` is a DB/cache
        // receiver can admit the name. Un-admitted it stays unfolded;
        // admitted it folds — the gate is configurable, never lossy on
        // evidence.
        $code = '<?php function f($store, $k) { return $store->get($k); }';
        $this->assertNotContains('Name|n:__DB_READ__', $this->canonicalTokens($code, true));

        $tokens = $this->tokensWithExtraEvidence($code, ['store']);
        $this->assertContains('Name|n:__DB_READ__', $tokens);
    }

    public function testExtraEvidenceNamesAreWholeWordAndCaseInsensitive(): void
    {
        // L-5 pins for the constructor seam: the merge lower-cases
        // admitted names (`'STORE'` must admit `$store`) and matches
        // whole words only (`'stor'` must NOT admit `$store`).
        $code = '<?php function f($store, $k) { return $store->get($k); }';
        $this->assertContains('Name|n:__DB_READ__', $this->tokensWithExtraEvidence($code, ['STORE']));
        $this->assertNotContains('Name|n:__DB_READ__', $this->tokensWithExtraEvidence($code, ['stor']));
    }

    public function testNullsafeReceiverPathIsGatedAndEvidenceAware(): void
    {
        // L-4: the gate and the hop walker both special-case the
        // nullsafe variants — pin both directions, on both nullsafe
        // shapes: `?->` on the terminal call (NullsafeMethodCall with
        // a plain PropertyFetch receiver) and `?->` on the property
        // hop itself (NullsafePropertyFetch — only the walker's
        // nullsafe arm can see this one).
        $this->assertNotContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f($k) { return $this->store?->get($k); }', true,
        ));
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f($k) { return $this->db?->get($k); }', true,
        ));
        $this->assertContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f($k) { return $this?->db->get($k); }', true,
        ));
        $this->assertNotContains('Name|n:__DB_READ__', $this->canonicalTokens(
            '<?php function f($k) { return $this?->store->get($k); }', true,
        ));
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

    public function testPartialApplicationHoleDoesNotDisturbSqlExtraction(): void
    {
        // php-parser 5.9 may hand firstStringArg() an arg list leading
        // with an ArgPlaceholder ("?"). The scan looks for the first real
        // string-valued Arg either way, so a hole-prefixed query must
        // fold to the very same canonical token stream as the plain call.
        $withHole = '<?php function f($pdo) {
            return $pdo->query(?, "SELECT * FROM audit_log WHERE id = 1");
        }';
        $plain = '<?php function f($pdo) {
            return $pdo->query("SELECT * FROM audit_log WHERE id = 1");
        }';
        $tokens = $this->canonicalTokens($withHole, dbAware: true);
        $this->assertContains('Name|n:__DB_READ__', $tokens);
        $this->assertSame($tokens, $this->canonicalTokens($plain, dbAware: true));
    }

    // ---------------------------------------------------------------
    // Payload signature (engine-003 half B): "same table+verb,
    // different columns" must be near-dup, not fabricated type-1.
    // ---------------------------------------------------------------

    public function testDifferentColumnPayloadsNoLongerFoldExact(): void
    {
        // The finding's repro, verbatim in spirit: three `exec()`
        // UPDATEs on the same table and verb touching different
        // column payloads. At HEAD all three folded to one EXACT
        // hash (022d7653 aggressive / 4534c6a6 strict) because the
        // aggressive literal pass erases the synthetic table/verb
        // strings to __STR and nothing else carried the payload.
        $credits = '<?php function f($db, $id) { $db->exec("UPDATE users SET credits = credits + ? WHERE id = ?"); }';
        $password = '<?php function g($db, $id) { $db->exec("UPDATE users SET password = ? WHERE id = ?"); }';
        $both = '<?php function h($db, $id) { $db->exec("UPDATE users SET credits = credits * 2, updated = ? WHERE id = ?"); }';

        $this->assertContains('Name|n:__DB_COLS_credits', $this->canonicalTokens($credits, true));
        $this->assertContains('Name|n:__DB_COLS_password', $this->canonicalTokens($password, true));
        $this->assertContains('Name|n:__DB_COLS_credits_updated', $this->canonicalTokens($both, true));

        $ha = $this->canonicalHashDbAware($credits);
        $hb = $this->canonicalHashDbAware($password);
        $hc = $this->canonicalHashDbAware($both);
        $this->assertNotSame($ha, $hb, 'different SET payloads must not fold EXACT');
        $this->assertNotSame($ha, $hc, 'different SET payloads must not fold EXACT');
        $this->assertNotSame($hb, $hc, 'different SET payloads must not fold EXACT');

        // …and all three still carry the shared __DB_WRITE__ op token,
        // which is what pulls them into the near-duplicate tiers.
        foreach ([$credits, $password, $both] as $code) {
            $this->assertContains('Name|n:__DB_WRITE__', $this->canonicalTokens($code, true));
        }
    }

    public function testSelectProjectionAndInsertListsCarrySignatures(): void
    {
        $star = '<?php function f($db) { return $db->query("SELECT * FROM users WHERE active = 1"); }';
        $cols = '<?php function f($db) { return $db->query("SELECT credits, password FROM users WHERE active = 1"); }';
        $this->assertContains('Name|n:__DB_COLS_*', $this->canonicalTokens($star, true));
        $this->assertContains('Name|n:__DB_COLS_credits_password', $this->canonicalTokens($cols, true));
        $this->assertNotSame(
            $this->canonicalHashDbAware($star),
            $this->canonicalHashDbAware($cols),
        );

        $insA = '<?php function f($db) { return $db->exec("INSERT INTO users (credits) VALUES (?)"); }';
        $insB = '<?php function f($db) { return $db->exec("INSERT INTO users (password) VALUES (?)"); }';
        $this->assertContains('Name|n:__DB_COLS_credits', $this->canonicalTokens($insA, true));
        $this->assertContains('Name|n:__DB_COLS_password', $this->canonicalTokens($insB, true));
        $this->assertNotSame(
            $this->canonicalHashDbAware($insA),
            $this->canonicalHashDbAware($insB),
        );
    }

    public function testSameColumnSetSpellingVariantsStillFold(): void
    {
        // Genuine same-payload clones must keep clustering through
        // the signature: case, member order, qualified (`u.`) and
        // backtick-quoted spellings all reduce to the same set…
        $plain = '<?php function f($db) { return $db->exec("UPDATE t SET updated_at = ?, credits = ?"); }';
        $variant = '<?php function g($db) { return $db->exec("UPDATE t SET CREDITS=?, `u`.`updated_at` = ?"); }';
        $this->assertSame(
            $this->canonicalHashDbAware($plain),
            $this->canonicalHashDbAware($variant),
        );
        $this->assertContains('Name|n:__DB_COLS_credits_updated_at', $this->canonicalTokens($variant, true));

        // …and the same payload through different driver entry points
        // (exec vs mysqli_query) folds identically.
        $cross = '<?php function h($db) { return mysqli_query($db, "UPDATE t SET credits = ?, updated_at = ?"); }';
        $this->assertSame(
            $this->canonicalHashDbAware($plain),
            $this->canonicalHashDbAware($cross),
        );
    }

    public function testSignatureTruncatesWidePayloadsWithArityTail(): void
    {
        $make = static fn (array $cols): string => '<?php function f($db) { return $db->exec("UPDATE t SET '
            . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $cols)) . '"); }';
        $fourteen = $make(array_map(static fn (int $i): string => "c$i", range(1, 14)));
        $thirteen = $make(array_map(static fn (int $i): string => "c$i", range(1, 13)));

        // Sorted slice keeps the alphabetically-first twelve; the
        // `n<total>` tail preserves the size signal past the cap.
        $toks14 = $this->canonicalTokens($fourteen, true);
        $this->assertContains('Name|n:__DB_COLS_c1_c10_c11_c12_c13_c14_c2_c3_c4_c5_c6_c7_n14', $toks14);
        $this->assertContains('Name|n:__DB_COLS_c1_c10_c11_c12_c13_c2_c3_c4_c5_c6_c7_c8_n13',
            $this->canonicalTokens($thirteen, true));

        // Documented coarse-by-design alias: two different 14-column
        // payloads that share the sorted first twelve collide. Pin the
        // behaviour so a future tightening notices the contract here.
        $twelveShared = array_map(static fn (int $i): string => "c$i", range(1, 12));
        $tailA = $make([...$twelveShared, 'z1', 'z2']);
        $tailB = $make([...$twelveShared, 'y1', 'y2']);
        $this->assertSame(
            $this->canonicalHashDbAware($tailA),
            $this->canonicalHashDbAware($tailB),
            'truncated signatures alias by design (first sorted slice + arity tail)',
        );
    }

    public function testPredicateOnlyVariantsStayCoarselyFolded(): void
    {
        // Coarse by design: WHERE predicates are NOT part of the
        // signature. Two DELETEs that name no payload columns keep
        // folding — pinning the accepted scope against a future
        // silent tightening.
        $d1 = '<?php function f($db) { return $db->exec("DELETE FROM users WHERE id = ?"); }';
        $d2 = '<?php function g($db) { return $db->exec("DELETE FROM users WHERE email = ?"); }';
        $this->assertSame($this->canonicalHashDbAware($d1), $this->canonicalHashDbAware($d2));
        $this->assertStringNotContainsString(
            '__DB_COLS',
            implode(' ', $this->canonicalTokens($d1, true)),
            'payload-less statements keep the pre-signature two-arg synthetic',
        );
    }

    public function testOnDuplicateKeyUpdateColumnsAreOutsideTheSignature(): void
    {
        // Coarse-by-design boundary (engine-S06b r1 LOW-1): source (2)
        // keys on the `SET` keyword, so MySQL `ON DUPLICATE KEY
        // UPDATE` assignment columns are not collected. Twins that
        // differ ONLY in the upsert column keep folding EXACT today
        // (measured: both carry __DB_COLS_a_b, hash bdcc8cb2…) — the
        // same scope the pre-signature base had. Pinning the boundary
        // means a future widening (scanning the ODKU LHS with the
        // existing assignment rule) trips this contract on purpose
        // instead of drifting into it silently.
        $upsertC = '<?php function f($db) { return $db->exec("INSERT INTO t (a,b) VALUES (1,2) ON DUPLICATE KEY UPDATE c = 3"); }';
        $upsertD = '<?php function g($db) { return $db->exec("INSERT INTO t (a,b) VALUES (1,2) ON DUPLICATE KEY UPDATE d = 3"); }';

        $this->assertContains('Name|n:__DB_COLS_a_b', $this->canonicalTokens($upsertC, true));
        $this->assertContains('Name|n:__DB_COLS_a_b', $this->canonicalTokens($upsertD, true));
        $this->assertStringNotContainsString(
            '__DB_COLS_a_b_c',
            implode(' ', $this->canonicalTokens($upsertC, true)),
            'the upsert clause stays outside the column set',
        );
        $this->assertSame(
            $this->canonicalHashDbAware($upsertC),
            $this->canonicalHashDbAware($upsertD),
            'ODKU-differing twins fold EXACT today (documented boundary)',
        );
    }

    public function testPayloadMarkerIsNeverParsedAsAnOpTag(): void
    {
        // Consumers that re-derive the op from a `__DB_<OP>__` name
        // after a cache round-trip drops the attribute (the tagger's
        // documented fallback) must not mistake the marker for an op.
        // The unterminated name is the guard — pin both directions.
        $tagger = new DbOperationTagger();
        $write = new Node\Expr\FuncCall(new Node\Name('__DB_WRITE__'));
        $this->assertSame(['db.write' => 1], $tagger->tag($write));
        $marker = new Node\Expr\FuncCall(new Node\Name('__DB_COLS_credits'));
        $this->assertSame([], $tagger->tag($marker));
    }

    public function testPlainModelStaticFoldsWhenClassAdmittedViaSeam(): void
    {
        // engine-003 F-2 end state: `User::all()` refuses on the bare
        // --db-aware path (pinned in
        // testStaticTierVerbsCarryTheirOwnEvidenceArms) and folds as
        // soon as `user` is admitted through the evidence seam. The
        // follow-up that is NOT this leg: a config bucket feeding
        // composer class signals into that seam (needs ConfigLoader/
        // Config locks; booked row in the S06b impl report).
        $code = '<?php function f() { return User::all(); }';
        $this->assertNotContains('Name|n:__DB_READ__', $this->canonicalTokens($code, true));
        $this->assertContains('Name|n:__DB_READ__', $this->tokensWithExtraEvidence($code, ['user']));
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
        return AstSerializer::tokens($blocks[0]->canonical);
    }

    /**
     * Fold $code with the constructor evidence seam. The Normalizer
     * itself takes no extra-names parameter (config wiring is a later
     * leg), so exercising the seam means pre-applying the
     * canonicalizer by hand — one place, reused by every seam pin.
     *
     * @param list<string> $extraNames
     * @return list<string>
     */
    private function tokensWithExtraEvidence(string $code, array $extraNames): array
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode($code);
        $blocks = (new BlockExtractor(minSize: 1))->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        (new DbOpCanonicalizer(extraReceiverEvidenceNames: $extraNames))->apply($blocks[0]->ast);
        (new Normalizer(mode: 'aggressive'))->normalize($blocks[0]);
        return AstSerializer::tokens($blocks[0]->canonical);
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
