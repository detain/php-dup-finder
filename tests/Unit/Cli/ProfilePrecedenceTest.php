<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Command;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * once-S27 (php-dup-finder-once-031): the profile-tier precedence census.
 *
 * The finding: --profile values used to be injected into the CLI override
 * dict (Command::resolveProfile()'s gap-fill), which placed them in the
 * TOP tier — so a bundled profile silently beat an explicit --config key.
 * PD-CFG-01's live proof: config min_block_size=2 vs profile 8 → 8 won,
 * "contradicting the documented precedence; a config-layer file loses
 * silently to CLI-level auto-injection."
 *
 * The cure: the profile is its own documented INTERMEDIATE tier —
 * defaults < profile < config file < CLI flags. This class pins the whole
 * tier story from both ends:
 *
 *  - door level (real CLI, real bundled profile, real JSON payload):
 *    the grave markers — arms that were RED on the pre-fix tree (the
 *    once-031 defect is reproducible there: profile values 8/25 win the
 *    2/1 spelled in the config file, and the run reports zero clusters
 *    where the file said it should find one) and GREEN after the tier
 *    separation. Non-vacuity mirrors keep the corpus honest: the same
 *    profile ALONE must still win against defaults, and the CLI flag on
 *    top must still beat the file.
 *  - loader level (ConfigLoader::load() with the new $profileOverrides
 *    tier): the per-setting matrix for every key the profile surface can
 *    carry — the nine flat OverrideResolver keys, the dedicated
 *    allowed_kinds and db_symbols legs, and the exclude stack (which is
 *    APPEND by item 5.2 and was always tier-correct: pinned so, not
 *    flipped).
 *  - the never-silent clause: every key the config file shadows from the
 *    profile is reported once on the load-note channel (the voice
 *    once-S67 introduced for the paths replacement), and the note's
 *    absence is pinned wherever nothing collides.
 *
 * What this class deliberately does NOT touch: the shell-S22 per-directory
 * layering (its 44-key admission belt lives in PerDirOverrideBeltTest,
 * green unedited — the tier change sits at profile-vs-ROOT-file only), and
 * the --profile=auto detection ordering (the once-S67 report-row delta is
 * out of §27 scope; see the step report).
 */
final class ProfilePrecedenceTest extends TestCase
{
    private ?string $scratch = null;

    protected function tearDown(): void
    {
        if ($this->scratch !== null) {
            self::deleteTree($this->scratch);
            $this->scratch = null;
        }
    }

    private function scratchDir(): string
    {
        if ($this->scratch === null) {
            $dir = sys_get_temp_dir() . '/phpdup-s27-' . getmypid() . '-' . spl_object_id($this);
            mkdir($dir, 0o777, true);
            $this->scratch = $dir;
        }

        return $this->scratch;
    }

    private static function deleteTree(string $dir): void
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

    // ------------------------------------------------------------------
    // Door level — the once-031 grave markers (real CLI end to end)
    // ------------------------------------------------------------------

    /**
     * THE grave marker for php-dup-finder-once-031. The corpus is two
     * 5-node twin functions (impact 5); the bundled `drupal` profile
     * carries min_block_size=8 and min_cluster_impact=25; the explicit
     * --config file spells min_block_size=2, min_cluster_impact=1.
     *
     * Pre-fix (RED, captured on the HEAD tree before implementation):
     * the profile injection beat the file, impact stayed 25, the pair was
     * ranked away and the payload reported ZERO clusters.
     * Post-fix: the file tier wins, the payload reports the pair — exit 0.
     */
    public function testProfileNoLongerBeatsConfigFile(): void
    {
        $dir      = $this->scratchDir();
        $corpus   = $this->mintTwinCorpus($dir . '/grave');
        $config   = $dir . '/grave-cfg.json';
        file_put_contents($config, json_encode(['min_block_size' => 2, 'min_cluster_impact' => 1], JSON_THROW_ON_ERROR));

        [$exit, $json] = $this->analyzeDoor($corpus, $dir . '/grave.json', [
            '--profile' => 'drupal',
            '--config'  => $config,
        ]);

        self::assertSame(0, $exit);
        self::assertGreaterThanOrEqual(1, count($json['clusters'] ?? []),
            'RED on the pre-fix tree: the profile (impact 25) beat the explicit config file (1) and the pair vanished — once-031');
    }

    /**
     * Non-vacuity mirror: the same profile WITHOUT any config file must
     * still win its tier (above defaults) — impact 25 filters the impact-5
     * pair to zero clusters. This is what makes the grave marker above
     * observe a real tier flip rather than an always-empty corpus.
     */
    public function testProfileTierStillWinsAgainstDefaultsAlone(): void
    {
        $dir    = $this->scratchDir();
        $corpus = $this->mintTwinCorpus($dir . '/mirror');

        [$exit, $json] = $this->analyzeDoor($corpus, $dir . '/mirror.json', ['--profile' => 'drupal']);

        self::assertSame(0, $exit);
        self::assertCount(0, $json['clusters'] ?? [],
            'the profile tier keeps authority over defaults — the grave marker above must flip something real');
    }

    /**
     * Top tier untouched: a CLI flag still outranks the config file AND
     * the profile (the pre-fix shape already behaved this way for
     * CLI-vs-profile because the old gap-fill skipped CLI-set keys — this
     * pin guards the property stays true once the tiers separate).
     */
    public function testCliFlagBeatsConfigFileAndProfile(): void
    {
        $dir    = $this->scratchDir();
        $corpus = $this->mintTwinCorpus($dir . '/cli');
        $config = $dir . '/cli-cfg.json';
        // File agrees with the profile on the filter (impact 25): only the
        // CLI flag can rescue the pair, so a cluster here IS the CLI tier.
        file_put_contents($config, json_encode(['min_block_size' => 2, 'min_cluster_impact' => 25], JSON_THROW_ON_ERROR));

        [$exit, $json] = $this->analyzeDoor($corpus, $dir . '/cli.json', [
            '--profile'    => 'drupal',
            '--config'     => $config,
            '--min-impact' => '1',
        ]);

        self::assertSame(0, $exit);
        self::assertGreaterThanOrEqual(1, count($json['clusters'] ?? []),
            '--min-impact sits in the CLI tier and must outrank the file/profile 25');
    }

    /**
     * Lowest tier documented: with no profile and no file the stock
     * default (min_cluster_impact 20) filters the pair away; a bare
     * config file alone beats both defaults-less runs — the pre-existing
     * file-over-default leg, restated inside the 4-tier matrix so this
     * class spells ALL four tiers, not only the moved one.
     */
    public function testDefaultsAndFileAloneKeepTheirDocumentedPositions(): void
    {
        $dir    = $this->scratchDir();
        $corpus = $this->mintTwinCorpus($dir . '/base');
        $config = $dir . '/base-cfg.json';
        file_put_contents($config, json_encode(['min_cluster_impact' => 1], JSON_THROW_ON_ERROR));

        [, $bare] = $this->analyzeDoor($corpus, $dir . '/base-a.json', []);
        self::assertCount(0, $bare['clusters'] ?? [],
            'with nothing above defaults the stock impact 20 filters the impact-5 pair');

        [, $filed] = $this->analyzeDoor($corpus, $dir . '/base-b.json', ['--config' => $config]);
        self::assertGreaterThanOrEqual(1, count($filed['clusters'] ?? []),
            'the config file alone still outranks defaults — the new tier inserted no shadow there');
    }

    /**
     * @param array<string, string> $extra
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function analyzeDoor(string $corpus, string $jsonPath, array $extra): array
    {
        @unlink($jsonPath);
        $app = new Application('phpdup', '0.1.0');
        $app->add(new Command());
        $tester = new CommandTester($app->find('analyze'));
        $exit = $tester->execute(array_merge([
            'paths'      => [$corpus],
            '--json'     => $jsonPath,
            '--no-cache' => true,
        ], $extra));
        self::assertFileExists($jsonPath, 'the --json sink must exist for every door arm: ' . $tester->getDisplay());

        return [$exit, (array) json_decode((string) file_get_contents($jsonPath), true)];
    }

    private function mintTwinCorpus(string $dir): string
    {
        mkdir($dir, 0o777, true);
        // 5-node function bodies: identical shapes, distinct names —
        // an exact clone pair whose cluster (impact 5) is gated purely by
        // the tier-resolved min_cluster_impact (25 vs 1 in the door arms).
        file_put_contents($dir . '/dup_a.php', "<?php\nfunction s27dup_a(): int {\n    return 42;\n}\n");
        file_put_contents($dir . '/dup_b.php', "<?php\nfunction s27dup_b(): int {\n    return 42;\n}\n");

        return $dir;
    }

    // ------------------------------------------------------------------
    // Loader level — per-setting 4-tier matrix for every profile-carried
    // flat key (the full liveness census: each key must move the resolved
    // Config at the profile tier and lose to the file tier exactly as
    // documented). The dict shape below is what Command::resolveProfile()
    // hands ConfigLoader::load() for a real profile file.
    // ------------------------------------------------------------------

    /** @return array<string, array{string,mixed,mixed,mixed}> */
    public static function tierKeyProvider(): array
    {
        return [
            // key        profile, file, CLI — every value distinct from
            // the file and profile neighbours on its comparison arms;
            // defaults are the Config field's own (asserted in arm 1).
            'min_block_size'      => ['min_block_size', 6, 4, 2],
            'max_block_size'      => ['max_block_size', 700, 600, 500],
            'normalization_mode'  => ['normalization_mode', 'strict', 'default', 'aggressive'],
            'similarity_threshold' => ['similarity_threshold', 0.55, 0.65, 0.75],
            'tree_threshold'      => ['tree_threshold', 0.60, 0.70, 0.80],
            'min_cluster_impact'  => ['min_cluster_impact', 30, 40, 50],
            'max_df'              => ['max_df', 0.20, 0.30, 0.40],
            'ngram_size'          => ['ngram_size', 3, 6, 8],
            'sort'                => ['sort', 'name:desc', 'file:desc', 'lines:asc'],
        ];
    }

    /**
     * @dataProvider tierKeyProvider
     */
    public function testFlatKeyFourTierMatrix(string $key, mixed $profileVal, mixed $fileVal, mixed $cliVal): void
    {
        $dir       = $this->scratchDir();
        $root      = $dir . '/m-' . $key;
        mkdir($root, 0o777, true);
        $config    = $root . '/phpdup.json';
        file_put_contents($config, json_encode([$key => $fileVal], JSON_THROW_ON_ERROR));

        // Sanity: each arm is discriminating (adjacent tiers never share a value).
        self::assertNotSame($profileVal, $fileVal, "provider collision: $key");
        self::assertNotSame($fileVal, $cliVal, "provider collision: $key");

        $loader = static fn(): ConfigLoader => new ConfigLoader(false);
        $pick   = static fn(Config $c): mixed => match ($key) {
            'min_block_size'       => $c->minBlockSize,
            'max_block_size'       => $c->maxBlockSize,
            'normalization_mode'   => $c->normalizationMode,
            'similarity_threshold' => $c->similarityThreshold,
            'tree_threshold'       => $c->treeThreshold,
            'min_cluster_impact'   => $c->minClusterImpact,
            'max_df'               => $c->maxDocumentFrequency,
            'ngram_size'           => $c->ngramSize,
            'sort'                 => $c->sort,
            default                => self::fail("unmapped provider key $key"),
        };

        // Arm 1 — nothing above defaults: the stock field value rules.
        $base = $pick($loader()->load([$root], null));
        self::assertNotSame($profileVal, $base, "provider collision with the Config default: $key");

        // Arm 2 — profile alone beats defaults (liveness census: the tier
        // must be observably live for EVERY key, once-S68 doctrine).
        self::assertSame($profileVal, $pick($loader()->load([$root], null, [], null, [$key => $profileVal])),
            "the profile tier must carry $key over defaults");

        // Arm 3 — the moved leg: the config file now beats the profile.
        // RED on the pre-fix tree (profile values entered via $overrides).
        self::assertSame($fileVal, $pick($loader()->load([$root], $config, [], null, [$key => $profileVal])),
            "$key: --config must outrank --profile (once-031 cure)");

        // Arm 4 — the CLI tier still beats the file (and so, transitively,
        // the whole stack below it).
        self::assertSame($cliVal, $pick($loader()->load([$root], $config, [$key => $cliVal], null, [$key => $profileVal])),
            "$key: the CLI override must outrank --config");
    }

    // ------------------------------------------------------------------
    // Dedicated legs: allowed_kinds and the db_symbols packs
    // ------------------------------------------------------------------

    public function testAllowedKindsFourTierMatrix(): void
    {
        $dir  = $this->scratchDir();
        $root = $dir . '/kinds';
        mkdir($root, 0o777, true);
        $config = $root . '/phpdup.json';
        file_put_contents($config, json_encode(['kinds' => ['foreach']], JSON_THROW_ON_ERROR));

        $loader = new ConfigLoader(false);

        // Profile alone wins (liveness).
        self::assertSame(['if'], $loader->load([$root], null, [], null, ['allowed_kinds' => ['if']])->allowedKinds);
        // The moved leg: file 'kinds' beats the profile tier. RED pre-fix.
        self::assertSame(['foreach'], $loader->load([$root], $config, [], null, ['allowed_kinds' => ['if']])->allowedKinds);
        // CLI dict top tier unchanged.
        self::assertSame(['switch'], $loader->load([$root], $config, ['allowed_kinds' => ['switch']], null, ['allowed_kinds' => ['if']])->allowedKinds);
    }

    public function testDbSymbolsPacksTierMatrix(): void
    {
        $dir  = $this->scratchDir();
        $root = $dir . '/dbsym';
        mkdir($root, 0o777, true);
        $loader = new ConfigLoader(false);

        // Profile pack alone is live (db-aware-* packs land via this leg).
        $only = $loader->load([$root], null, [], null, ['db_symbols_methods' => ['profget' => 'db.read']]);
        self::assertSame(['profget' => 'db.read'], $only->dbSymbolsMethods);

        // The moved leg: a file naming THIS bucket replaces the profile pack.
        $fileCfg = $root . '/full.json';
        file_put_contents($fileCfg, json_encode(['db_symbols' => ['methods' => ['fileget' => 'db.write']]], JSON_THROW_ON_ERROR));
        $won = $loader->load([$root], $fileCfg, [], null, ['db_symbols_methods' => ['profget' => 'db.read']]);
        self::assertSame(['fileget' => 'db.write'], $won->dbSymbolsMethods,
            'once-031: the config file must own the bucket it names');

        // Honest partiality: a file naming ONLY the other bucket leaves the
        // profile's methods pack live (per-bucket shadowing, no invented
        // cross-bucket wipe).
        $otherCfg = $root . '/other.json';
        file_put_contents($otherCfg, json_encode(['db_symbols' => ['functions' => ['filefn' => 'db.query']]], JSON_THROW_ON_ERROR));
        $split = $loader->load([$root], $otherCfg, [], null, ['db_symbols_methods' => ['profget' => 'db.read']]);
        self::assertSame(['profget' => 'db.read'], $split->dbSymbolsMethods);
        self::assertSame(['filefn' => 'db.query'], $split->dbSymbolsFunctions);
    }

    // ------------------------------------------------------------------
    // The exclude stack — append semantics already tier-correct (item 5.2)
    // ------------------------------------------------------------------

    public function testExcludeStackCarriesTheProfileBetweenDefaultsAndFile(): void
    {
        $dir  = $this->scratchDir();
        $root = $dir . '/excl';
        mkdir($root, 0o777, true);
        $config = $root . '/phpdup.json';
        file_put_contents($config, json_encode(['exclude' => ['**/cfg-layer/**']], JSON_THROW_ON_ERROR));

        $loader = new ConfigLoader(false);
        $exclude = $loader->load([$root], $config, ['exclude' => ['**/cli-layer/**']], ['**/prof-layer/**'])->exclude;

        $atDefaults = array_search('**/vendor/**', $exclude, true);
        $atProfile  = array_search('**/prof-layer/**', $exclude, true);
        $atFile     = array_search('**/cfg-layer/**', $exclude, true);
        $atCli      = array_search('**/cli-layer/**', $exclude, true);
        self::assertIsInt($atDefaults);
        self::assertIsInt($atProfile);
        self::assertIsInt($atFile);
        self::assertIsInt($atCli);
        self::assertLessThan($atProfile, $atDefaults, 'defaults stack first');
        self::assertLessThan($atFile, $atProfile, 'profile sits above defaults, below the file');
        self::assertLessThan($atCli, $atFile, 'CLI dict lands last');

        // The documented reset opt-out discards the profile layer too:
        $reset = $root . '/reset.json';
        file_put_contents($reset, json_encode(['exclude' => ['**/cfg-layer/**'], 'exclude_reset' => true], JSON_THROW_ON_ERROR));
        $rExcl = $loader->load([$root], $reset, [], ['**/prof-layer/**'])->exclude;
        self::assertNotContains('**/prof-layer/**', $rExcl, 'exclude_reset drops everything inherited below it — profile included');
        self::assertContains('**/cfg-layer/**', $rExcl);
    }

    // ------------------------------------------------------------------
    // Never silently replaced: the shadow note
    // ------------------------------------------------------------------

    public function testShadowNoteNamesEveryFileShadowedProfileKeyOnce(): void
    {
        $dir  = $this->scratchDir();
        $root = $dir . '/note';
        mkdir($root, 0o777, true);
        $config = $root . '/phpdup.json';
        // Collides on two keys; sort:collide and kinds:collide below.
        file_put_contents($config, json_encode([
            'min_block_size' => 4,
            'ngram_size'     => 6,
            'kinds'          => ['foreach'],
        ], JSON_THROW_ON_ERROR));

        $loader = new ConfigLoader(false);
        $loader->load([$root], $config, [], null, [
            'min_block_size' => 8,
            'ngram_size'     => 3,
            'sort'           => 'name:desc',   // file does not name it — NOT shadowed
            'allowed_kinds'  => ['if'],        // shadowed via the file's 'kinds'
        ]);

        $notes = $loader->loadNotes();
        self::assertCount(1, $notes, 'one census note per load, whatever the number of shadowed keys');
        self::assertStringContainsString('shadows profile value(s)', $notes[0]);
        self::assertStringContainsString('allowed_kinds, min_block_size, ngram_size', $notes[0],
            'sorted shadow census — the kinds/db Symbols name-shift legs are tracked too');
        self::assertStringNotContainsString('sort', $notes[0]);

        // A profile-only run has no file to shadow with — silent, correctly.
        $quiet = new ConfigLoader(false);
        $quiet->load([$root], null, [], null, ['min_block_size' => 8]);
        self::assertSame([], $quiet->loadNotes(), 'the note names a file collision; no file, no note');

        // A file-only run shadows nothing from anywhere.
        $alsoQuiet = new ConfigLoader(false);
        $alsoQuiet->load([$root], $config, [], null, []);
        self::assertSame([], $alsoQuiet->loadNotes(), 'the profile contributed no keys — nothing was shadowed');
    }

    public function testLoadSignatureBackCompatWithoutProfileTier(): void
    {
        // Four-arg (pre-once-S27) calls must resolve EXACTLY like the new
        // five-arg call with an explicit empty tier — every historical
        // caller and belt (PerDirOverrideBeltTest included) keeps its
        // semantics untouched.
        $dir  = $this->scratchDir();
        $root = $dir . '/compat';
        mkdir($root, 0o777, true);
        $config = $root . '/phpdup.json';
        file_put_contents($config, json_encode(['min_block_size' => 10], JSON_THROW_ON_ERROR));

        $loaderFour = new ConfigLoader(false);
        $four       = $loaderFour->load([$root], $config, ['min_cluster_impact' => 33], null);
        $loaderFive = new ConfigLoader(false);
        $five       = $loaderFive->load([$root], $config, ['min_cluster_impact' => 33], null, []);
        self::assertSame($four->minBlockSize, $five->minBlockSize);
        self::assertSame($four->minClusterImpact, $five->minClusterImpact);
        self::assertSame([], $loaderFive->loadNotes());
    }

    // ------------------------------------------------------------------
    // Auto-tune interaction: the profile keeps locking its knobs
    // ------------------------------------------------------------------

    public function testAutoTuneNeverOverwritesAProfileKnob(): void
    {
        // The --auto-tune contract (once-S26 wording) claims a knob set
        // "by --profile" is never overwritten. Pre-fix that held because
        // the profile hid inside $overrides; the tier separation must keep
        // it true by consulting the new array explicitly.
        $dir    = $this->scratchDir();
        $corpus = $dir . '/tune';
        mkdir($corpus, 0o777, true);
        for ($i = 0; $i < 10; $i++) {
            file_put_contents($corpus . "/f$i.php", "<?php\nfunction s27_t_$i(): int { return $i; }\n");
        }

        $cmd    = new Command();
        $input  = new ArrayInput(['paths' => [$corpus], '--auto-tune' => true], $cmd->getDefinition());
        $out    = new BufferedOutput();
        $resolve = new \ReflectionMethod($cmd, 'resolveAutoTune');

        $overrides = [];
        $resolve->invokeArgs($cmd, [$input, $out, [$corpus], &$overrides, null, ['min_block_size' => 4]]);
        $display = $out->fetch();

        self::assertStringContainsString('min_block_size=4 kept (explicitly set)', $display,
            'the tuner narrative must see the profile tier (it no longer rides $overrides)');
        self::assertArrayNotHasKey('min_block_size', $overrides,
            'a tuned suggestion must never slip into the CLI tier over a profile-set knob');
        self::assertSame(['min_cluster_impact', 'max_df'], array_keys($overrides),
            'the genuinely-open tier knobs still fill (tiny band: impact 0, df 0.5)');
        self::assertSame(0, $overrides['min_cluster_impact']);
        self::assertSame(0.5, $overrides['max_df']);
    }
}
