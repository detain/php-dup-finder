<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Extraction\Block;
use Phpdup\Parallel\PreprocessWorker;
use PHPUnit\Framework\TestCase;

/**
 * Admission-truth belt for per-directory .phpdup.json files.
 *
 * ORIGIN (ft-r2 [M-1] remediation, owner-gate R8): every key
 * ConfigLoader::shapeOverrides() ADMITS must be CONSUMED by
 * Config::withOverrides(), or sit deliberately in the BANKED allowlist —
 * the mechanical guard that caught the m1 orphan class (candidate_budget,
 * fixed in e221652) and its silently-dropped siblings.
 *
 * once-S67 (php-dup-finder-once-077) closes the loophole the origin left
 * open: "consumed" in the property-move-proxy sense (a value reaching a
 * Config field of the per-file copy) is NOT honoring unless a per-file
 * READER consumes that copy. A subtree value must therefore either drive
 * an observable per-subtree behavior (the worker arms below run the REAL
 * load() → effectiveFor()/PreprocessWorker channels) or the key must be
 * REFUSED loudly at discovery. The BANKED list is emptied: every former
 * bank member (sort + the five optional_blocks_* flat keys) joined the
 * refused roster, as did the ten whole-run stances that passed the
 * property proxy without ever being observed per-file, and the fourteen
 * root-only keys (incl. paths, report, apply, the ML/IR sidecar pair)
 * whose old crime was vanishing in total silence. The path/ref family
 * (shell-S22) and the stance family keep their dedicated discovery arms
 * untouched — those ten keys live in LEGACY_REFUSED here only to pin the
 * equation.
 *
 * Totality (testAdmissionIsTotalBothDirections): KNOWN_KEYS = admitted ∪
 * refused in BOTH directions, disjointly. A new root key that is neither
 * admitted-and-live nor refused fails the belt; a dropped refusal fails
 * it too.
 *
 * HAND-MAINTAINED DISCLOSURE (p0d-r3 [m-1]): fullPerDirectoryPayload()
 * is the only input to the emitted-side derivation. The totality test is
 * now the mechanical bound that makes staleness loud: an admitted key
 * missing from the payload shrinks emitted below the pinned 12 and
 * breaks the equation against the parent roster.
 *
 * SHRINK PROTOCOL (shell-S22, applied again by once-S67): dropping keys
 * from the admit list drops them from the payload and lowers BOTH counts
 * (emitted 23→12, consumed 17→12, banked 6→0). The other side is pinned
 * by testShedAndRootOnlyKeysShapeToNothing and the refusal matrix — a
 * re-admission cannot sneak back through the payload half alone.
 *
 * ADAPT DISCLOSURE (brief-sanctioned): the planted-orphan arm used to
 * assert 'sort' as the naturally-orphaned real key; 'sort' is refused
 * now, so the arm pins the mirror — the planted orphan still bites and a
 * truly honored key must not misfire.
 */
final class PerDirOverrideBeltTest extends TestCase
{
    /** @var list<string> */
    private array $tmps = [];

    protected function tearDown(): void
    {
        foreach ($this->tmps as $dir) {
            self::rmrf($dir);
        }
        $this->tmps = [];
    }

    /**
     * once-S67: the bank is EMPTY and stays empty — anything here would
     * be a key admitted per-directory yet unconsumed per-file, the exact
     * dishonesty the admission-totality item removed. The equation still
     * pins: emitted = consumed ∪ banked.
     */
    private const BANKED = [];

    /**
     * The ten keys with dedicated discovery refusal arms that predate
     * once-S67 (stance family + fingerprint_id_mode + path/ref family +
     * ml_pair_allow_private). Listed only to pin the totality equation;
     * their throw behavior rides their era tests
     * (FingerprintIdModeConfigTest, PerDirectoryConfigTest) AND the
     * shared matrix below.
     */
    private const LEGACY_REFUSED = [
        'fingerprint_id_mode', 'scope', 'score_mode', 'max_ted_size',
        'max_hole_coverage', 'ml_pair_allow_private', 'baseline',
        'baseline_out', 'diff_base', 'debug_log',
    ];

    /**
     * The 11 per-directory admitted parents (once-S67 roster; 12 flat
     * emitted keys — kinds lands as allowed_kinds, db_symbols as the two
     * buckets).
     */
    private const ADMITTED_PARENTS = [
        'exclude', 'exclude_reset', 'min_block_size', 'max_block_size',
        'normalization_mode', 'ngram_size', 'kinds', 'db_symbols',
        'db_aware', 'trinity_collapse', 'low_memory',
    ];

    /**
     * The per-directory payload covering every shape the emit loop
     * admits (once-S67 roster).
     *
     * @return array<string,mixed>
     */
    public static function fullPerDirectoryPayload(): array
    {
        return [
            'min_block_size'       => 17,
            'max_block_size'       => 413,
            'normalization_mode'   => 'strict',
            'ngram_size'           => 7,
            'kinds'                => ['method'],
            // item 5.2: both exclude keys are admitted and CONSUMED
            // (append-merge + reset opt-out in Config::mergeExclude()).
            'exclude'              => ['belt/exclude/**'],
            'exclude_reset'        => true,
            'db_symbols' => [
                'methods'   => ['BeltEntity::probe' => 'db.read'],
                'functions' => ['belt_fn' => 'db.write'],
            ],
            // once-S67 new admits — withOverrides honored these isset
            // legs since the gate-era wiring; discovery admits them now.
            'db_aware'          => true,
            'trinity_collapse'  => true,
            'low_memory'        => true,
        ];
    }

    public function testEveryEmittedPerDirKeyIsConsumedOrBanked(): void
    {
        $emitted = self::emittedKeys();
        // once-S67 shrink (disclosed): 23→12. The shed half is pinned
        // refused by two independent arms below.
        self::assertCount(12, $emitted, 'fully-populated payload must exercise every admitted shape');

        [$consumed, $orphans] = self::classifyKeys($emitted);

        sort($orphans);
        $banked = self::BANKED;
        sort($banked);
        self::assertSame(
            $banked,
            $orphans,
            'shapeOverrides admits keys that withOverrides neither consumes nor has banked (owner-gate R8): '
            . implode(', ', array_diff($orphans, $banked))
            . ' — a newly admitted key must be consumed in Config::withOverrides() AND honored by a per-file reader (once-S67), never merely BANKED.',
        );
        self::assertCount(12, $consumed, 'the consumed half of the belt is 12 emitted minus 0 banked (once-S67)');
    }

    /**
     * shell-S22 (php-dup-finder-shell-026): the emitted-set pin has a
     * negative half. The path/ref-bearing family — the keys that choose a
     * file to WRITE or an external ref to shell out to — must never be
     * admitted per-directory, not even as a silent drop: a payload made
     * of nothing else must shape to NOTHING. Re-admitting any of the four
     * (or inventing a fifth write-bearing key and shipping it through
     * shapeOverrides) fails here before it can ever reach a derived Config.
     */
    public function testPathRefBearingKeysAreNotAdmittedPerDirectory(): void
    {
        $loader = new ConfigLoader();
        $method = new \ReflectionMethod($loader, 'shapeOverrides');

        foreach (['baseline', 'baseline_out', 'diff_base', 'debug_log'] as $rootOnlyKey) {
            /** @var array<string,mixed> $emitted */
            $emitted = $method->invoke($loader, [$rootOnlyKey => '/belt/planted-' . $rootOnlyKey]);
            self::assertSame(
                [],
                $emitted,
                "shapeOverrides must not admit the root-only key '$rootOnlyKey' per-directory (shell-S22)",
            );
        }
    }

    /**
     * once-S67 (php-dup-finder-once-077) — SHRINK-PROTOCOL other half:
     * every shed stance key and every newly refused root key must shape
     * to NOTHING in the emit loop. The payloads below are exactly what
     * the pre-S67 roster used to admit (or carry); the emit map must now
     * stay empty for each, so a re-admission in the loop alone fails both
     * this arm and the discovery matrix.
     */
    public function testShedAndRootOnlyKeysShapeToNothing(): void
    {
        $loader = new ConfigLoader();
        $method = new \ReflectionMethod($loader, 'shapeOverrides');

        foreach (array_keys(self::refusedKeyValues()) as $key) {
            /** @var array<string,mixed> $emitted */
            $emitted = $method->invoke($loader, [$key => self::refusedKeyValues()[$key]]);
            self::assertSame(
                [],
                $emitted,
                "shapeOverrides must not admit '$key' per-directory (once-S67 refusal roster)",
            );
        }
    }

    /**
     * Refused key => a schema-VALID root value, so the refusal must bite
     * on KEY PRESENCE, never hide behind a shape error. Covers the 25
     * once-S67 refusals AND the 10 legacy-era arms (their dedicated throw
     * blocks predate S67; the matrix pins them under one voice).
     *
     * @return array<string,array<int,mixed>|string|int|float|bool>
     */
    public static function refusedKeyValues(): array
    {
        return [
            'paths'                 => ['/belt/elsewhere'],
            'similarity_threshold'  => 0.44,
            'tree_threshold'        => 0.45,
            'min_cluster_impact'    => 7,
            'max_df'                => 0.33,
            'cache_dir'             => '/belt/cache',
            'parallelism'           => 'off',
            'workers'               => 2,
            'incremental'           => false,
            'lazy_ast'              => true,
            'sort'                  => 'members:asc',
            'ted_weights'           => 'semantic',
            'optional_blocks'       => ['enabled' => false, 'containment' => 0.12, 'min_overlap' => 0.13, 'max_per_cluster' => 9, 'min_segment_length' => 4],
            'report'                => ['html' => '/belt/report'],
            'normalization'         => ['plugins' => []],
            'scorer'                => 'default',
            'ir_threshold'          => 0.5,
            'ml_pair_url'           => 'https://belt.invalid',
            'ml_pair_threshold'     => 0.9,
            'apply'                 => true,
            'fail_on_impact'        => 11,
            'max_clusters'          => 12,
            'candidate_budget'      => 777,
            'candidate_mass_filter' => true,
            'window_oversized'      => true,
            // legacy family (dedicated arms; same voice pinned here)
            'fingerprint_id_mode'   => 'numbered',
            'scope'                 => 'all',
            'score_mode'            => 'global',
            'max_ted_size'          => 100,
            'max_hole_coverage'     => 0.5,
            'ml_pair_allow_private' => false,
            'baseline'              => '/belt/baseline.json',
            'baseline_out'          => '/belt/baseline-out.json',
            'diff_base'             => 'HEAD~1',
            'debug_log'             => '/belt/debug.log',
        ];
    }

    /**
     * once-S67: THE REFUSAL MATRIX through the REAL discovery channel.
     * A one-key .phpdup.json under a scan root — carrying a value the
     * ROOT config would happily accept — must make load() throw, naming
     * the key and the offending file with the era message voice.
     */
    public function testEveryRefusedKeyInPerDirFileFailsTheLoad(): void
    {
        foreach (self::refusedKeyValues() as $key => $value) {
            [$root, $sub] = $this->makeTree();
            file_put_contents($sub . '/.phpdup.json', json_encode([$key => $value]) ?: '{}');

            $loader = new ConfigLoader(warnOnSkip: false);
            try {
                $loader->load([$root], null);
                self::fail("load() must refuse the per-directory key '$key' (once-S67 totality)");
            } catch (\RuntimeException $e) {
                self::assertStringStartsWith(
                    $key . ' is not a per-directory key (root phpdup.json only): ',
                    $e->getMessage(),
                    "refusal for '$key' must keep the era message voice (root-name FIRST, then the file)",
                );
                self::assertStringContainsString($sub . '/.phpdup.json', $e->getMessage());
            }
        }
    }

    /**
     * once-S67: the totality equation, both directions, disjointly.
     */
    public function testAdmissionIsTotalBothDirections(): void
    {
        $known   = (new \ReflectionClass(ConfigLoader::class))->getConstant('KNOWN_KEYS');
        $refused = (new \ReflectionClass(ConfigLoader::class))->getConstant('REFUSED_PER_DIRECTORY');
        self::assertIsArray($known);
        self::assertIsArray($refused);

        $covered = array_merge(self::ADMITTED_PARENTS, $refused, self::LEGACY_REFUSED);
        self::assertCount(46, $known, 'the root-config universe is 46 keys');
        self::assertCount(count($covered), $known, 'no key may be counted twice across the three sides');
        sort($known);
        sort($covered);
        self::assertSame(
            $known,
            $covered,
            'every KNOWN_KEYS member must be admitted-parent, refused, or legacy-refused (once-S67 totality)',
        );

        self::assertCount(25, $refused, 'the once-S67 roster refuses exactly 25 new keys');
        self::assertEmpty(array_intersect(self::ADMITTED_PARENTS, $refused));
        self::assertEmpty(array_intersect(self::ADMITTED_PARENTS, self::LEGACY_REFUSED));
        self::assertEmpty(array_intersect($refused, self::LEGACY_REFUSED));
    }

    /**
     * once-S67: the ADMITTED half of the matrix through the REAL
     * discovery channel. Every admitted parent must (a) actually land in
     * the per-directory override map via load(), (b) be OBSERVED by
     * Config::effectiveFor() for a file in that subtree, and (c) leave
     * the root config untouched. No reflection, no shortcut — this is
     * the same path PreprocessWorker walks per file.
     */
    public function testAdmittedKeysAreHonoredByRealLoadAndEffectiveFor(): void
    {
        // key => [payload value, callable(Block|Config check), extra flat keys?]
        $cases = [
            'min_block_size'     => [['min_block_size' => 40], static fn (Config $eff, Config $root): bool => $eff->minBlockSize === 40 && $root->minBlockSize !== 40],
            'max_block_size'     => [['max_block_size' => 12], static fn (Config $eff, Config $root): bool => $eff->maxBlockSize === 12 && $root->maxBlockSize !== 12],
            'normalization_mode' => [['normalization_mode' => 'strict'], static fn (Config $eff, Config $root): bool => $eff->normalizationMode === 'strict' && $root->normalizationMode !== 'strict'],
            'ngram_size'         => [['ngram_size' => 7], static fn (Config $eff, Config $root): bool => $eff->ngramSize === 7 && $root->ngramSize !== 7],
            'kinds'              => [['kinds' => ['method']], static fn (Config $eff, Config $root): bool => $eff->allowedKinds === ['method'] && $root->allowedKinds !== ['method']],
            'exclude'            => [['exclude' => ['belt-sub/**']], static fn (Config $eff, Config $root): bool => in_array('belt-sub/**', $eff->exclude, true) && !in_array('belt-sub/**', $root->exclude, true)],
            'exclude_reset'      => [['exclude_reset' => true, 'exclude' => ['only/this/**']], static fn (Config $eff, Config $root): bool => $eff->exclude === ['only/this/**']],
            'db_aware'           => [['db_aware' => true], static fn (Config $eff, Config $root): bool => $eff->dbAware === true && $root->dbAware === false],
            'trinity_collapse'   => [['trinity_collapse' => true], static fn (Config $eff, Config $root): bool => $eff->trinityCollapse === true && $root->trinityCollapse === false],
            'db_symbols'         => [['db_symbols' => ['methods' => ['belt_probe' => 'db.read']]], static fn (Config $eff, Config $root): bool => ($eff->dbSymbolsMethods['belt_probe'] ?? null) === 'db.read' && !isset($root->dbSymbolsMethods['belt_probe'])],
            'low_memory'         => [['low_memory' => true], static fn (Config $eff, Config $root): bool => $eff->lowMemory === true && $root->lowMemory === false],
        ];

        foreach ($cases as $label => [$payload, $check]) {
            [$root, $sub] = $this->makeTree();
            file_put_contents($sub . '/.phpdup.json', json_encode($payload) ?: '{}');

            $config = (new ConfigLoader(warnOnSkip: false))->load([$root], null);
            self::assertNotSame([], $config->perDirectoryOverrides, "'$label' was refused or dropped from discovery (per-dir map empty)");
            $eff = $config->effectiveFor($sub . '/Widget.php');
            self::assertTrue($check($eff, $config), "'$label' not honored by effectiveFor() (or leaked to the root config)");
        }
    }

    /**
     * once-S67: OBSERVABLE per-file honoring through the real worker
     * channel (PreprocessWorker consumes effectiveFor() per file —
     * src/Parallel/PreprocessWorker.php, the per-file $cfg). These arms
     * fail if admission returns WITHOUT behavior: a key that merely
     * "moves into the config" but never reaches the extractor, the
     * normalizer, or the fingerprinter is exactly the once-077 crime.
     */
    public function testAdmittedKeysChangeWorkerBehaviorPerSubtree(): void
    {
        // ---- min_block_size: subtree floor hides non-exempt blocks ----
        // (engine-057 doctrine: top-level function/method/closure/arrow
        // blocks are exempt from the min gate — so the observable effect
        // of a raised per-subtree floor is the loss of STATEMENT-kind
        // blocks like 'if', exactly as it would be from the root config.)
        [$root, $sub] = $this->makeTree();
        file_put_contents($sub . '/Small.php', "<?php\nfunction guard(\$a) { if (\$a) { echo \$a; echo 2; echo 3; echo 4; return \$a; } return \$a; }\n");
        file_put_contents($sub . '/.phpdup.json', json_encode(['min_block_size' => 400]));
        $cfg = (new ConfigLoader(warnOnSkip: false))->load([$root], null);
        $gated = array_map(static fn (Block $b): string => $b->kind, self::blocksOf((new PreprocessWorker($cfg, astDiskCache: false))->process([$sub . '/Small.php'])));
        self::assertContains('function', $gated, 'top-level blocks stay exempt (engine-057)');
        self::assertNotContains('if', $gated, 'per-directory min_block_size must gate statement-kind extraction under that directory');
        $bareCfg = Config::defaults([$root]);
        $ungated = array_map(static fn (Block $b): string => $b->kind, self::blocksOf((new PreprocessWorker($bareCfg, astDiskCache: false))->process([$sub . '/Small.php'])));
        self::assertContains('if', $ungated, 'at root defaults the same file yields the if-block (the pin above only bites because of the subtree floor)');

        // ---- db_aware: canonical rewrites land ONLY in the subtree ----
        [$root, $sub] = $this->makeTree();
        file_put_contents($sub . '/Raw.php', "<?php\nfunction dropRow(\$q) { return \$q->delete(); }\n");
        file_put_contents($sub . '/.phpdup.json', json_encode(['db_aware' => true]));
        $cfg = (new ConfigLoader(warnOnSkip: false))->load([$root], null);
        $block = self::onlyBlock((new PreprocessWorker($cfg, astDiskCache: false))->process([$sub . '/Raw.php']));
        self::assertStringContainsString(
            '__DB_DELETE__',
            (string) serialize($block->canonical),
            'per-directory db_aware must run the DbOpCanonicalizer for that subtree (the old silence: admitted nowhere, honored nowhere)',
        );
        $rootOnly = (new PreprocessWorker($bareCfg, astDiskCache: false))->process([$sub . '/Raw.php']);
        self::assertStringNotContainsString(
            '__DB_DELETE__',
            (string) serialize(self::onlyBlock($rootOnly)->canonical),
            'the root config (db_aware off) must keep the subtree block verbatim',
        );

        // ---- trinity_collapse: the __DB_UPSERT__ rewrite is per-subtree ----
        [$root, $sub] = $this->makeTree();
        file_put_contents(
            $sub . '/Crud.php',
            "<?php\nfunction bump(\$id) { \$user = User::find(\$id); \$user->setName('Bob'); \$user->save(); }\n",
        );
        file_put_contents($sub . '/.phpdup.json', json_encode(['db_aware' => true, 'trinity_collapse' => true]));
        $cfg = (new ConfigLoader(warnOnSkip: false))->load([$root], null);
        $collapsed = (new PreprocessWorker($cfg, astDiskCache: false))->process([$sub . '/Crud.php']);
        self::assertStringContainsString(
            '__DB_UPSERT__',
            (string) serialize(self::onlyBlock($collapsed)->canonical),
            'per-directory trinity_collapse must fold the CRUD trinity for that subtree',
        );
        [$root2, $sub2] = $this->makeTree();
        file_put_contents(
            $sub2 . '/Crud.php',
            "<?php\nfunction bump(\$id) { \$user = User::find(\$id); \$user->setName('Bob'); \$user->save(); }\n",
        );
        file_put_contents($sub2 . '/.phpdup.json', json_encode(['db_aware' => true, 'trinity_collapse' => false]));
        $cfg2 = (new ConfigLoader(warnOnSkip: false))->load([$root2], null);
        self::assertStringNotContainsString(
            '__DB_UPSERT__',
            (string) serialize(self::onlyBlock((new PreprocessWorker($cfg2, astDiskCache: false))->process([$sub2 . '/Crud.php']))->canonical),
            'trinity_collapse=false in the subtree must NOT collapse (same db_aware on both sides — the trinity flag alone is the discriminator)',
        );

        // ---- db_symbols: user map merges per-subtree ----
        [$root, $sub] = $this->makeTree();
        file_put_contents($sub . '/Sym.php', "<?php\nfunction p(\$e) { return \$e->belt_probe(); }\n");
        file_put_contents($sub . '/.phpdup.json', json_encode(['db_aware' => true, 'db_symbols' => ['methods' => ['belt_probe' => 'db.read']]]));
        $cfg = (new ConfigLoader(warnOnSkip: false))->load([$root], null);
        self::assertStringContainsString(
            '__DB_READ__',
            (string) serialize(self::onlyBlock((new PreprocessWorker($cfg, astDiskCache: false))->process([$sub . '/Sym.php']))->canonical),
            'per-directory db_symbols.functions must fold into the subtree canonicalizer',
        );

        // ---- ngram_size: the fingerprint bag is computed at the subtree size ----
        [$root, $sub] = $this->makeTree();
        file_put_contents($sub . '/Wide.php', "<?php\nfunction wide(\$a, \$b, \$c) { \$r = 0;\n"
            . "  for (\$i = 0; \$i < 9; \$i++) { \$r += \$a * \$i + \$b - \$c; }\n  return \$r; }\n");
        file_put_contents($sub . '/.phpdup.json', json_encode(['ngram_size' => 7]));
        $cfg = (new ConfigLoader(warnOnSkip: false))->load([$root], null);
        $subBag = self::functionBlock((new PreprocessWorker($cfg, astDiskCache: false))->process([$sub . '/Wide.php']))->ngramBag;
        $rootBag = self::functionBlock((new PreprocessWorker($bareCfg, astDiskCache: false))->process([$sub . '/Wide.php']))->ngramBag;
        self::assertIsArray($subBag);
        self::assertIsArray($rootBag);
        self::assertNotSame(
            count($subBag),
            count($rootBag),
            'per-directory ngram_size must change the bag (longer n-grams span fewer positions on the same token stream)',
        );

        // ---- normalization_mode: strict keeps literals, default folds them ----
        [$root, $sub] = $this->makeTree();
        file_put_contents($sub . '/Lit.php', "<?php\nfunction lit() { return 42; }\n");
        file_put_contents($sub . '/.phpdup.json', json_encode(['normalization_mode' => 'strict']));
        $cfg = (new ConfigLoader(warnOnSkip: false))->load([$root], null);
        $strictDump = (string) serialize(self::onlyBlock((new PreprocessWorker($cfg, astDiskCache: false))->process([$sub . '/Lit.php']))->canonical);
        $defaultDump = (string) serialize(self::onlyBlock((new PreprocessWorker($bareCfg, astDiskCache: false))->process([$sub . '/Lit.php']))->canonical);
        self::assertStringContainsString('42', $strictDump, 'strict normalization must keep the literal');
        self::assertNotSame($strictDump, $defaultDump, 'the subtree mode must actually change the normalized AST (default folds the literal away)');

        // ---- kinds: only methods survive extraction under the subtree ----
        [$root, $sub] = $this->makeTree();
        file_put_contents($sub . '/Mixed.php', "<?php\nfunction topFn(\$x) { return \$x + 1; }\nclass W { public function m(\$y) { return \$y - 1; } }\n");
        file_put_contents($sub . '/.phpdup.json', json_encode(['kinds' => ['method']]));
        $cfg = (new ConfigLoader(warnOnSkip: false))->load([$root], null);
        $kinds = array_map(static fn (Block $b): string => $b->kind, self::blocksOf((new PreprocessWorker($cfg, astDiskCache: false))->process([$sub . '/Mixed.php'])));
        self::assertSame(['method'], $kinds, 'per-directory kinds must restrict extraction to method blocks only');
        $bareKinds = array_map(static fn (Block $b): string => $b->kind, self::blocksOf((new PreprocessWorker($bareCfg, astDiskCache: false))->process([$sub . '/Mixed.php'])));
        self::assertContains('function', $bareKinds, 'at root defaults the same file yields function blocks too (the pin bites)');
    }

    /**
     * once-S67 (paths leg, the silent-clobber half): a root config file
     * whose paths key REPLACES the caller's scan roots must say so on the
     * load-note channel. Equal values, file-absent runs, and the file-
     * stands-in-for-argv posture (CLI roots empty) stay silent — the note
     * is about the surprise, not about precedence existing.
     */
    public function testRootConfigPathsReplacementIsNeverSilent(): void
    {
        [$root] = $this->makeTree();
        $file = $root . '/phpdup.json';

        // (a) replacement: CLI names the tree, file names somewhere else.
        file_put_contents($file, json_encode(['paths' => ['/belt/elsewhere']]));
        $loader = new ConfigLoader(warnOnSkip: false);
        $cfg = $loader->load([$root], $file);
        self::assertSame(['/belt/elsewhere'], $cfg->paths, 'the documented file-over-argv precedence still applies');
        $notes = $loader->loadNotes();
        self::assertCount(1, $notes);
        self::assertStringContainsString('replaces the scan roots', $notes[0]);
        self::assertStringContainsString($root, $notes[0], 'the note must name the overridden CLI roots');
        self::assertStringContainsString('/belt/elsewhere', $notes[0], 'and the replacement list');

        // (b) agreement: same values — nothing was taken, no note.
        file_put_contents($file, json_encode(['paths' => [$root]]));
        $loader = new ConfigLoader(warnOnSkip: false);
        $cfg = $loader->load([$root], $file);
        self::assertSame([$root], $cfg->paths);
        self::assertSame([], $loader->loadNotes());

        // (c) no paths key at all: roots stay the caller's, no note.
        file_put_contents($file, json_encode(['min_block_size' => 9]));
        $loader = new ConfigLoader(warnOnSkip: false);
        $cfg = $loader->load([$root], $file);
        self::assertSame([$root], $cfg->paths);
        self::assertSame([], $loader->loadNotes());

        // (d) file-stands-in-for-argv (empty CLI roots): the file IS the
        //     authority — replacing nothing, so no note.
        file_put_contents($file, json_encode(['paths' => ['/belt/elsewhere']]));
        $loader = new ConfigLoader(warnOnSkip: false);
        $cfg = $loader->load([], $file);
        self::assertSame(['/belt/elsewhere'], $cfg->paths);
        self::assertSame([], $loader->loadNotes());
    }

    /**
     * Non-vacuity proof: a planted key that shapeOverrides-like admission
     * emits but withOverrides ignores MUST classify as an unconsumed,
     * unbanked orphan — i.e. the belt mechanism actually bites.
     *
     * once-S67 adapt (disclosed): the second assertion pinned 'sort' as
     * the natural real orphan; sort is REFUSED now, so the arm pins the
     * mirror — the planted orphan still bites and an honored key must
     * not misfire.
     */
    public function testPlantedOrphanIsCaughtByTheSameMechanism(): void
    {
        $emitted   = self::emittedKeys();
        $emitted[] = 'planted_orphan';
        // classifyKeys exercises the planted key exactly like a real
        // emitted key via its $extraSentinel fallback.
        [, $orphans] = self::classifyKeys($emitted, extraSentinel: 'not-a-real-key');
        self::assertContains('planted_orphan', $orphans, 'the belt must fail loud on unbanked orphans');
        self::assertNotContains('min_block_size', $orphans, 'a truly consumed key must not misfire as an orphan (post-shrink face of the same mechanism)');
    }

    /**
     * Derive the EMITTED key set behaviorally from the private admission
     * table itself (no hand copy): feed the full payload, take result keys.
     *
     * @return array<string,mixed> emitted key => sentinel value
     */
    private static function emittedSentinels(): array
    {
        $loader = new ConfigLoader();
        $method = new \ReflectionMethod($loader, 'shapeOverrides');
        /** @var array<string,mixed> $emitted */
        $emitted = $method->invoke($loader, self::fullPerDirectoryPayload());
        self::assertIsArray($emitted);
        return $emitted;
    }

    /** @return list<string> */
    private static function emittedKeys(): array
    {
        return array_keys(self::emittedSentinels());
    }

    /**
     * For each emitted key: build withOverrides([key => sentinel]) off a
     * common root and diff the resulting public readonly property vectors
     * against withOverrides([]). Any movement means the key is consumed.
     *
     * NOTE (once-S67): consumption here is the PROPERTY-MOVE proxy — the
     * necessary half. The sufficient half (per-file READERS actually
     * honoring the moved value) is the worker/matrix arms above. The
     * proxy alone was the loophole the original belt allowed: it kept
     * keys like fail_on_impact green whose readers never see the derived
     * Config. Do not add a key to this roster for passing this half
     * alone.
     *
     * @param list<string> $keys
     * @return array{0: array<string,list<string>>, 1: list<string>} [consumedKey=>movedProps, orphans]
     */
    private static function classifyKeys(array $keys, mixed $extraSentinel = null): array
    {
        $sentinels = self::emittedSentinels();
        if ($extraSentinel !== null) {
            $sentinels['planted_orphan'] = $extraSentinel;
        }

        // Non-empty baseline exclude so BOTH item-5.2 keys move a property
        // against it: `exclude` appends, `exclude_reset` clears to [].
        $root     = new Config(paths: [__DIR__], exclude: ['belt-root/**']);
        $baseline = get_object_vars($root->withOverrides([]));

        $consumed = [];
        $orphans  = [];
        foreach ($keys as $key) {
            $derived = get_object_vars($root->withOverrides([$key => $sentinels[$key] ?? $extraSentinel]));
            $moved   = [];
            foreach ($baseline as $prop => $value) {
                if (($derived[$prop] ?? null) !== $value) {
                    $moved[] = $prop;
                }
            }
            if ($moved === []) {
                $orphans[] = $key;
            } else {
                $consumed[$key] = $moved;
            }
        }
        return [$consumed, $orphans];
    }

    /**
     * Build a real scan tree (temp root + one subdirectory) for the
     * discovery-channel arms. Register the root for tearDown cleanup.
     *
     * @return array{0: string, 1: string} [root, sub]
     */
    private function makeTree(): array
    {
        $root = sys_get_temp_dir() . '/phpdup-s67-' . bin2hex(random_bytes(6));
        mkdir($root . '/sub', 0777, true);
        $this->tmps[] = $root;
        return [$root, $root . '/sub'];
    }

    /**
     * @param list<array{type: string, file: string, block?: Block}> $rows
     * @return list<Block>
     */
    private static function blocksOf(array $rows): array
    {
        $blocks = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'block' && isset($row['block'])) {
                $blocks[] = $row['block'];
            }
        }
        return $blocks;
    }

    /**
     * @param list<array{type: string, file: string, block?: Block}> $rows
     */
    private static function onlyBlock(array $rows): Block
    {
        $blocks = self::blocksOf($rows);
        self::assertCount(1, $blocks, 'expected exactly one extracted block for the pin payload');
        return $blocks[0];
    }

    /**
     * The function-kind block of a payload file (statement-kind blocks may
     * co-exist inside it; the function is the pin's stable handle).
     *
     * @param list<array{type: string, file: string, block?: Block}> $rows
     */
    private static function functionBlock(array $rows): Block
    {
        foreach (self::blocksOf($rows) as $block) {
            if ($block->kind === 'function') {
                return $block;
            }
        }
        self::fail('pin payload must yield a function block');
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
