<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ml;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Ml\PairFeatures;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\JaccardSimilarity;

final class PairFeaturesTest extends TestCase
{
    public function testFeatureVersionIsStable(): void
    {
        $this->assertSame(1, PairFeatures::FEATURE_VERSION);
        $this->assertSame(1, PairFeatures::featureVersion());
    }

    public function testIdenticalBlocksProduceMaxScores(): void
    {
        $code = '<?php function f($id) { return User::find($id); }';
        $a = $this->buildBlock($code);
        $b = $this->buildBlock($code);
        $features = (new PairFeatures())->extract($a, $b);

        $this->assertSame(1, $features['feature_version']);
        $this->assertTrue($features['structural_hash_match']);
        $this->assertSame(1.0, $features['ngram_jaccard']);
        $this->assertSame(1.0, $features['ir_token_jaccard']);
        $this->assertSame(1.0, $features['db_tag_jaccard']);
        $this->assertSame(1.0, $features['block_size_ratio']);
        $this->assertTrue($features['kind_match']);
        $this->assertSame('function', $features['block_a_kind']);
    }

    public function testCrossLibraryReadsHaveHighDbTagAndIrSignal(): void
    {
        $a = $this->buildBlock('<?php function f($id) { return User::find($id); }');
        $b = $this->buildBlock('<?php function f($pdo, $id) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        }');
        $features = (new PairFeatures())->extract($a, $b);
        // Surface ngram Jaccard is low (different call shapes), but
        // the DB-tag and IR bands should fire.
        $this->assertGreaterThan(0.0, $features['db_tag_jaccard']);
        $this->assertGreaterThan(0.0, $features['ir_token_jaccard']);
    }

    public function testUnrelatedBlocksHaveLowerSignal(): void
    {
        $a = $this->buildBlock('<?php function f($x) { return $x + 1; }');
        $b = $this->buildBlock('<?php function f($pdo, $id) { return $pdo->query("SELECT * FROM users"); }');
        $features = (new PairFeatures())->extract($a, $b);
        $this->assertFalse($features['structural_hash_match']);
        // Surface n-gram Jaccard captures the call-shape mismatch.
        $this->assertLessThan(0.5, $features['ngram_jaccard']);
        // IR-token Jaccard is also low when the operation shapes
        // differ (one is pure arithmetic, the other a DB query).
        $this->assertLessThan(0.7, $features['ir_token_jaccard']);
    }

    public function testFeatureBundleIsJsonSerialisable(): void
    {
        $a = $this->buildBlock('<?php function f($id) { return User::find($id); }');
        $b = $this->buildBlock('<?php function f($id) { return $id; }');
        $features = (new PairFeatures())->extract($a, $b);
        $json = json_encode($features);
        $this->assertIsString($json);
        $this->assertNotFalse($json);
        $this->assertNotEmpty($json);
    }

    public function testKindMatchReflectsBlockKind(): void
    {
        $a = $this->buildBlock('<?php function f($x) { return $x; }');
        $b = $this->buildBlock('<?php $f = function($x) { return $x; };');
        $features = (new PairFeatures())->extract($a, $b);
        $this->assertFalse($features['kind_match']);
        $this->assertSame('function', $features['block_a_kind']);
        $this->assertSame('closure', $features['block_b_kind']);
    }

    // ---- engine-033: per-block memoization ----------------------------------

    /**
     * Seam counter (engine-033): a block appearing in d pairs pays ONE
     * full derivation (summarizer + tagger + IR lift), not d. Three
     * blocks forming three pairs must derive exactly three per-block
     * feature sets — and repeats of seen pairs must derive nothing.
     */
    public function testPerBlockFeaturesAreDerivedOnceAcrossSharedPairs(): void
    {
        $x = $this->buildBlock('<?php function f($id) { return User::find($id); }');
        $y = $this->buildBlock('<?php function g($pdo, $id) { $s = $pdo->prepare("SELECT * FROM t WHERE id = ?"); $s->execute([$id]); return $s->fetch(); }');
        $z = $this->buildBlock('<?php function h($x) { return $x + 1; }');

        $features = new PairFeatures();
        $features->extract($x, $y);
        $features->extract($x, $z);
        $features->extract($y, $z);
        $this->assertSame(3, $features->perBlockDerivationCount());

        // Repeated pairs (forward or flipped) ride the memo.
        $features->extract($x, $y);
        $features->extract($y, $x);
        $features->extract($x, $z);
        $this->assertSame(3, $features->perBlockDerivationCount());
    }

    /**
     * The memo must not change values: every vector produced by one
     * shared instance across the fixture fan-out must equal the
     * vector a fresh (cold) instance produces for the same pair.
     */
    public function testSharedInstanceVectorsEqualColdInstanceVectors(): void
    {
        $blocks = [];
        foreach (self::PIN_FIXTURES as $src) {
            $blocks[] = $this->buildBlock($src);
        }
        $shared = new PairFeatures();
        foreach ($blocks as $i => $a) {
            foreach ($blocks as $j => $b) {
                if ($i >= $j) {
                    continue;
                }
                $cold = json_encode((new PairFeatures())->extract($a, $b));
                $warm = json_encode($shared->extract($a, $b));
                $this->assertSame($cold, $warm, "shared/cold mismatch for pair $i|$j");
            }
        }
        // 9 snippets, 36 pairs: each block derived exactly once.
        $this->assertSame(9, $shared->perBlockDerivationCount());
    }

    /**
     * engine-033 cache-honesty guard: swapping a block's AST (the
     * lazy reload / unloadAst dance of BlockAstLoader) must refresh
     * the memo instead of serving a vector derived from the old
     * tree — landing on exactly what a cold instance reports for
     * the new state.
     */
    public function testMemoRefreshesWhenAstIdentityChanges(): void
    {
        $a = $this->buildBlock('<?php function f($id) { return User::find($id); }');
        $b = $this->buildBlock('<?php function g($x) { return $x + 1; }');

        $features = new PairFeatures();
        $warm = $features->extract($a, $b);
        $this->assertGreaterThan(0.0, $warm['ir_token_jaccard']);
        $this->assertSame(2, $features->perBlockDerivationCount());

        $a->unloadAst();
        $afterUnload = $features->extract($a, $b);
        $this->assertSame(3, $features->perBlockDerivationCount(), 'AST swap must refresh the entry');

        $cold = new PairFeatures();
        $pristine = $this->buildBlock('<?php function f($id) { return User::find($id); }');
        $pristine->unloadAst();
        $this->assertSame(
            json_encode($cold->extract($pristine, $b)),
            json_encode($afterUnload),
            'refreshed entry must match the cold-path vector for the same state',
        );
        $this->assertSame(0.0, $afterUnload['ir_token_jaccard'], 'unloaded AST falls back exactly like the cold path');
    }

    /**
     * Same refresh contract for a re-normalized block: replacing the
     * canonical node must re-derive instead of serving stale vars.
     */
    public function testMemoRefreshesWhenCanonicalReplaced(): void
    {
        $a = $this->buildBlock('<?php function f($id) { return User::find($id); }');
        $b = $this->buildBlock('<?php function g($x) { return $x + 1; }');

        $features = new PairFeatures();
        $before = $features->extract($a, $b);
        $this->assertSame(2, $features->perBlockDerivationCount());

        // Swap in a structurally different canonical (SQL calls and
        // DB tags the old tree never had) — under aggressive mode a
        // same-arity rename would canonicalize back to the identical
        // shape, so arity/content is the discriminator.
        $a->canonical = $this->buildBlock(
            '<?php function s($pdo, $id) { $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([$id]); return $stmt->fetch(); }'
        )->canonical;
        $after = $features->extract($a, $b);
        $this->assertSame(3, $features->perBlockDerivationCount(), 'canonical swap must re-derive');
        $this->assertNotSame(json_encode($before), json_encode($after));

        // And the refreshed vector must equal the cold-path vector
        // for the same state — memoization changes caching, never values.
        $this->assertSame(
            json_encode((new PairFeatures())->extract($a, $b)),
            json_encode($after),
        );
    }

    /**
     * The two-loop JaccardSimilarity reports 0.0 for two empty bags;
     * the wire contract pins 1.0 (vacuous perfect match) there — a
     * value the sidecar was trained on, so the branch is kept local
     * and asserted directly.
     */
    public function testBothEmptyBagsKeepVacuousPerfectMatch(): void
    {
        $a = $this->buildBlock(self::PIN_FIXTURES['arith']);
        $b = $this->buildBlock(self::PIN_FIXTURES['empty']);
        $features = (new PairFeatures())->extract($a, $b);

        // Neither fixture emits calls or DB tags: both bags empty.
        $this->assertSame(1.0, $features['call_jaccard']);
        $this->assertSame(1.0, $features['db_tag_jaccard']);
        // Sanity: the shared implementation alone would say 0.0.
        $this->assertSame(0.0, (new JaccardSimilarity())->similarity([], []));
    }

    /**
     * Golden-style pin (engine-033 acceptance): the full feature
     * vector for a fixture pair set, captured from the
     * pre-memoization extractor on current master BEFORE this
     * refactor and replayed here verbatim. Any drift in the
     * refactor — values, key order, or types — fails this test.
     */
    public function testFeatureVectorsAreByteIdenticalToPreChangeCapture(): void
    {
        foreach (self::PINNED_VECTORS as $pairKey => $expectedJson) {
            $parts = explode('|', $pairKey);
            if (count($parts) === 3) {
                [$aKey, $marker, $bKey] = $parts;
                $this->assertSame('noast', $marker, 'only supported modifier is noast');
            } else {
                [$aKey, $bKey] = $parts;
                $marker = '';
            }
            $a = $this->buildBlock(self::PIN_FIXTURES[$aKey]);
            $b = $this->buildBlock(self::PIN_FIXTURES[$bKey]);
            if ($marker === 'noast') {
                $a->unloadAst();
            }
            $features = (new PairFeatures())->extract($a, $b);
            $this->assertSame(
                $expectedJson,
                json_encode($features),
                "feature vector drifted from the pre-change capture for pair $pairKey",
            );
        }
    }

    /** Snippet set the pinned vectors were captured from. */
    private const PIN_FIXTURES = [
        'user_find'     => '<?php function a($id) { return User::find($id); }',
        'pdo_select'    => '<?php function b($pdo, $id) { $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([$id]); return $stmt->fetch(); }',
        'arith'         => '<?php function c($x) { return $x + 1; }',
        'closure'       => '<?php $f = function($id) { return User::find($id); };',
        'method_log'    => '<?php class K { public function go($a, $b) { $this->logger->log("hit"); return $a > $b ? $a : $b; } }',
        'empty'         => '<?php function z() { return; }',
        'ret_float_a'   => '<?php function r1($x) { return $x; return 1; }',
        'ret_float_b'   => '<?php function r2($y) { return $y; }',
        'user_find_dup' => '<?php function a2($id) { return User::find($id); }',
    ];

    /**
     * Captured from `PairFeatures` at master immediately BEFORE the
     * engine-033 memoization refactor (probe harness, fresh
     * instance per pair). Pair key = "snippetA|snippetB", with an
     * embedded "noast" marker when A's AST is unloaded pre-extract.
     *
     * @var array<string,string>
     */
    private const PINNED_VECTORS = [
            "user_find|user_find_dup" => "{\"feature_version\":1,\"structural_hash_match\":true,\"ngram_jaccard\":1,\"var_jaccard\":1,\"call_jaccard\":1,\"return_jaccard\":1,\"db_tag_jaccard\":1,\"ir_token_jaccard\":1,\"block_size_ratio\":1,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "pdo_select|user_find" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.10869565217391304,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4,\"block_size_ratio\":0.38461538461538464,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "pdo_select|ret_float_a" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.06666666666666667,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4166666666666667,\"block_size_ratio\":0.3076923076923077,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "pdo_select|ret_float_b" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.07142857142857142,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4,\"block_size_ratio\":0.23076923076923078,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "pdo_select|user_find_dup" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.10869565217391304,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4,\"block_size_ratio\":0.38461538461538464,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "arith|user_find" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.2777777777777778,\"var_jaccard\":1,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4444444444444444,\"block_size_ratio\":0.8,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "arith|pdo_select" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.09090909090909091,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4166666666666667,\"block_size_ratio\":0.3076923076923077,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "arith|closure" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.15789473684210525,\"var_jaccard\":1,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4444444444444444,\"block_size_ratio\":0.8888888888888888,\"kind_match\":false,\"block_a_kind\":\"function\",\"block_b_kind\":\"closure\"}",
            "arith|method_log" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.08333333333333333,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.35294117647058826,\"block_size_ratio\":0.38095238095238093,\"kind_match\":false,\"block_a_kind\":\"function\",\"block_b_kind\":\"method\"}",
            "arith|empty" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0,\"var_jaccard\":0,\"call_jaccard\":1,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.2222222222222222,\"block_size_ratio\":0.375,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "arith|ret_float_a" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.3333333333333333,\"var_jaccard\":1,\"call_jaccard\":1,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.7777777777777778,\"block_size_ratio\":1,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "arith|ret_float_b" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.3076923076923077,\"var_jaccard\":1,\"call_jaccard\":1,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.625,\"block_size_ratio\":0.75,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "arith|user_find_dup" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.2777777777777778,\"var_jaccard\":1,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4444444444444444,\"block_size_ratio\":0.8,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "closure|user_find" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.7857142857142857,\"var_jaccard\":1,\"call_jaccard\":1,\"return_jaccard\":1,\"db_tag_jaccard\":1,\"ir_token_jaccard\":1,\"block_size_ratio\":0.9,\"kind_match\":false,\"block_a_kind\":\"closure\",\"block_b_kind\":\"function\"}",
            "closure|pdo_select" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.06382978723404255,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4,\"block_size_ratio\":0.34615384615384615,\"kind_match\":false,\"block_a_kind\":\"closure\",\"block_b_kind\":\"function\"}",
            "closure|method_log" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.07894736842105263,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.25,\"block_size_ratio\":0.42857142857142855,\"kind_match\":false,\"block_a_kind\":\"closure\",\"block_b_kind\":\"method\"}",
            "closure|empty" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0,\"var_jaccard\":0,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.3333333333333333,\"block_size_ratio\":0.3333333333333333,\"kind_match\":false,\"block_a_kind\":\"closure\",\"block_b_kind\":\"function\"}",
            "closure|ret_float_a" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.1,\"var_jaccard\":1,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4444444444444444,\"block_size_ratio\":0.8888888888888888,\"kind_match\":false,\"block_a_kind\":\"closure\",\"block_b_kind\":\"function\"}",
            "closure|ret_float_b" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.1875,\"var_jaccard\":1,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.6666666666666666,\"block_size_ratio\":0.6666666666666666,\"kind_match\":false,\"block_a_kind\":\"closure\",\"block_b_kind\":\"function\"}",
            "closure|user_find_dup" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.7857142857142857,\"var_jaccard\":1,\"call_jaccard\":1,\"return_jaccard\":1,\"db_tag_jaccard\":1,\"ir_token_jaccard\":1,\"block_size_ratio\":0.9,\"kind_match\":false,\"block_a_kind\":\"closure\",\"block_b_kind\":\"function\"}",
            "method_log|user_find" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.10526315789473684,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.25,\"block_size_ratio\":0.47619047619047616,\"kind_match\":false,\"block_a_kind\":\"method\",\"block_b_kind\":\"function\"}",
            "method_log|pdo_select" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.2641509433962264,\"var_jaccard\":0.5,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.2631578947368421,\"block_size_ratio\":0.8076923076923077,\"kind_match\":false,\"block_a_kind\":\"method\",\"block_b_kind\":\"function\"}",
            "method_log|ret_float_a" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.05405405405405406,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.35294117647058826,\"block_size_ratio\":0.38095238095238093,\"kind_match\":false,\"block_a_kind\":\"method\",\"block_b_kind\":\"function\"}",
            "method_log|ret_float_b" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.058823529411764705,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.3333333333333333,\"block_size_ratio\":0.2857142857142857,\"kind_match\":false,\"block_a_kind\":\"method\",\"block_b_kind\":\"function\"}",
            "method_log|user_find_dup" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.10526315789473684,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.25,\"block_size_ratio\":0.47619047619047616,\"kind_match\":false,\"block_a_kind\":\"method\",\"block_b_kind\":\"function\"}",
            "empty|user_find" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0,\"var_jaccard\":0,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.3333333333333333,\"block_size_ratio\":0.3,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "empty|pdo_select" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0,\"var_jaccard\":0,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.2,\"block_size_ratio\":0.11538461538461539,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "empty|method_log" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0,\"var_jaccard\":0,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.125,\"block_size_ratio\":0.14285714285714285,\"kind_match\":false,\"block_a_kind\":\"function\",\"block_b_kind\":\"method\"}",
            "empty|ret_float_a" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0,\"var_jaccard\":0,\"call_jaccard\":1,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.2222222222222222,\"block_size_ratio\":0.375,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "empty|ret_float_b" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0,\"var_jaccard\":0,\"call_jaccard\":1,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.3333333333333333,\"block_size_ratio\":0.5,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "empty|user_find_dup" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0,\"var_jaccard\":0,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.3333333333333333,\"block_size_ratio\":0.3,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "ret_float_a|user_find" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.21052631578947367,\"var_jaccard\":1,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4444444444444444,\"block_size_ratio\":0.8,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "ret_float_a|ret_float_b" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.5454545454545454,\"var_jaccard\":1,\"call_jaccard\":1,\"return_jaccard\":0.5,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.625,\"block_size_ratio\":0.75,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "ret_float_a|user_find_dup" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.21052631578947367,\"var_jaccard\":1,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.4444444444444444,\"block_size_ratio\":0.8,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "ret_float_b|user_find" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.3333333333333333,\"var_jaccard\":1,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.6666666666666666,\"block_size_ratio\":0.6,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "ret_float_b|user_find_dup" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.3333333333333333,\"var_jaccard\":1,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0.6666666666666666,\"block_size_ratio\":0.6,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
            "user_find|noast|pdo_select" => "{\"feature_version\":1,\"structural_hash_match\":false,\"ngram_jaccard\":0.10869565217391304,\"var_jaccard\":0.3333333333333333,\"call_jaccard\":0,\"return_jaccard\":0,\"db_tag_jaccard\":1,\"ir_token_jaccard\":0,\"block_size_ratio\":0.38461538461538464,\"kind_match\":true,\"block_a_kind\":\"function\",\"block_b_kind\":\"function\"}",
    ];

    private function buildBlock(string $code): \Phpdup\Extraction\Block
    {
        $stmts = (new AstParser())->parseCode($code);
        $blocks = (new BlockExtractor(minSize: 1))->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        $block = $blocks[0];
        (new Normalizer(mode: 'aggressive'))->normalize($block);
        $block->structuralHash = (new SubtreeHasher())->hash($block->canonical);
        $block->ngramBag = (new NgramFingerprint(3))->fingerprint($block->canonical);
        return $block;
    }
}
