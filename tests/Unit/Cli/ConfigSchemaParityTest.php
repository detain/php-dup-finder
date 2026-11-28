<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Similarity\EditCostModel;
use PHPUnit\Framework\TestCase;

/**
 * shell-S31 (php-dup-finder-shell-036) — the schema↔admission mirror, pinned.
 *
 * ConfigLoader::validate() refuses every key outside its admitted set, and
 * docs/config-schema.json tells IDEs/schema consumers which keys exist. For
 * as long as that pair was "kept in sync by hand" it drifted silently:
 * 43 admitted keys vs 38 documented properties (apply, debug_log,
 * exclude_reset, normalization, ted_weights were missing from the doc).
 * This test asserts the two sets are EQUAL in both directions, so any drift
 * on either side fails the suite instead of lying to one of the two
 * audiences. The validate() docblock that carried the hand-sync comment now
 * names this test as the pin.
 */
final class ConfigSchemaParityTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function schema(): array
    {
        $path = dirname(__DIR__, 3) . '/docs/config-schema.json';
        self::assertFileExists($path, 'parity belt needs the committed schema document');
        $decoded = json_decode((string)file_get_contents($path), true);
        self::assertIsArray($decoded, 'docs/config-schema.json must decode to an object');
        self::assertArrayHasKey('properties', $decoded, 'schema must carry a properties map');
        self::assertIsArray($decoded['properties']);
        return $decoded;
    }

    /** @param array<int,array-key> $keys @return list<string> */
    private static function sortedNames(array $keys): array
    {
        $names = array_map('strval', $keys);
        sort($names);
        return $names;
    }

    /**
     * The core mirror contract: every admitted key is documented, and every
     * documented property is admitted. No third state.
     */
    public function testAdmittedKeysAndDocumentedPropertiesAreTheSameSet(): void
    {
        $schema     = self::schema();
        $admitted   = self::sortedNames(ConfigLoader::KNOWN_KEYS);
        $documented = self::sortedNames(array_keys($schema['properties']));

        $missingFromSchema = array_values(array_diff($admitted, $documented));
        $unknownToLoader   = array_values(array_diff($documented, $admitted));

        self::assertSame(
            [],
            $missingFromSchema,
            'admitted config key(s) missing from docs/config-schema.json (loader accepts what the doc forbids): '
                . implode(', ', $missingFromSchema)
        );
        self::assertSame(
            [],
            $unknownToLoader,
            'docs/config-schema.json documents key(s) the loader refuses as unknown (doc promises what the code rejects): '
                . implode(', ', $unknownToLoader)
        );
    }

    /**
     * The loader hard-fails unknown keys, so the doc must be a CLOSED set —
     * an open schema would let undocumented keys look valid again and defeat
     * the set-equality pin above.
     */
    public function testRootSchemaIsAClosedSet(): void
    {
        $schema = self::schema();
        self::assertSame(
            false,
            $schema['additionalProperties'] ?? null,
            'docs/config-schema.json must keep additionalProperties:false at the root: '
                . 'validate() refuses unknown keys, so an open document re-opens the shell-036 drift class'
        );
    }

    /**
     * Every property documents its shape (type) and its meaning
     * (description) — the half of "schema consumers now agree" that set
     * equality cannot express.
     */
    public function testEveryDocumentedPropertyDeclaresTypeAndDescription(): void
    {
        $schema = self::schema();
        foreach ($schema['properties'] as $name => $definition) {
            self::assertIsArray($definition, "$name: property definition must be an object");
            self::assertArrayHasKey('type', $definition, "$name: schema property declares no type");
            self::assertIsString($definition['type'], "$name: type must be a string");
            self::assertArrayHasKey('description', $definition, "$name: schema property has no description");
            self::assertNotSame('', $definition['description'], "$name: empty description");
        }
    }

    /**
     * The five keys shell-036 caught as undocumented (apply landed with
     * shell-S27; the rest here with shell-S31) each carry a type AND a
     * default, per the plan's acceptance list.
     */
    public function testKeysAddedForTheDriftCarryTypeAndDefault(): void
    {
        $schema     = self::schema();
        $properties = $schema['properties'];
        foreach (['exclude_reset', 'ted_weights', 'normalization', 'debug_log', 'apply'] as $name) {
            self::assertArrayHasKey($name, $properties, "$name: must be documented in the schema");
            self::assertArrayHasKey('type', $properties[$name], "$name: must declare a type");
            self::assertArrayHasKey('default', $properties[$name], "$name: must declare its default");
        }
        // Values that mirror the constructor defaults (Phpdup\Cli\Config).
        self::assertSame(false, $properties['exclude_reset']['default']);
        self::assertSame('default', $properties['ted_weights']['default']);
        self::assertSame([], $properties['normalization']['default']);
        self::assertSame(null, $properties['debug_log']['default']);
    }

    /**
     * The documented ted_weights enum is the SAME list the loader validates
     * against — derived from the live public API, not a second hand-synced
     * roster.
     */
    public function testTedWeightsDocumentedEnumMatchesTheAdmittedModelSet(): void
    {
        $schema = self::schema();
        self::assertSame(
            EditCostModel::MODELS,
            $schema['properties']['ted_weights']['enum'] ?? null,
            'schema enum for ted_weights must equal EditCostModel::MODELS'
        );
    }

    /**
     * The agreement runs through the door too: for each key the drift
     * restored, the documented default/representative value PASSES the
     * loader's validate() — schema consumers and loader now accept exactly
     * the same payloads (the --validate-config exit-0 acceptance, unit form).
     */
    public function testDocumentedValuesPassTheLoadersOwnAdmission(): void
    {
        $loader = new ConfigLoader();
        $loader->validate(['exclude_reset' => false]);
        $loader->validate(['exclude_reset' => true]);
        $loader->validate(['ted_weights' => 'default']);
        $loader->validate(['ted_weights' => 'semantic']);
        $loader->validate(['debug_log' => 'phpdup-debug.log']);
        $loader->validate(['normalization' => ['plugins' => []]]);
        $loader->validate(['normalization' => ['plugins' => ['Acme\\PhpDupPlugin']]]);
        $loader->validate(['apply' => true]);
        $this->addToAssertionCount(8);
    }

    /**
     * normalization is a nested contract: the schema documents exactly the
     * one sub-key the loader admits. Proven behaviorally through the loader
     * door (accept plugins, refuse anything else) so no second hand-list is
     * baked into this belt.
     */
    public function testNormalizationSubKeyMirrorIsExact(): void
    {
        $schema = self::schema();
        $norm   = $schema['properties']['normalization'];
        self::assertSame(
            ['plugins'],
            array_keys($norm['properties'] ?? []),
            'schema must document exactly the admitted normalization sub-key'
        );
        self::assertSame(false, $norm['additionalProperties'] ?? null);

        $loader = new ConfigLoader();
        $loader->validate(['normalization' => ['plugins' => ['Acme\\PhpDupPlugin']]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown config key 'normalization.plugins_two'");
        $loader->validate(['normalization' => ['plugins' => [], 'plugins_two' => []]]);
    }

    // ------------------------------------------------------------------
    // fix-round-1 (r1 HIGH-1): the columns BELOW the key set are pinned too.
    // ------------------------------------------------------------------

    /**
     * An int a string/bool door can never take, and a string no other kind
     * of door takes. If the loader ever ADMITS one of these for a key that
     * documents the other kind, the accept branch of doorKindOf() reports
     * exactly that — which is the drift, not a test bug.
     */
    private const PROBE_INT = 12345;
    private const PROBE_STR = '__phpdup_parity_probe__';

    /**
     * The wording ConfigLoader::validate() uses to refuse a wrong KIND
     * (needle => the JSON type it proves). This is NOT a second hand-synced
     * schema: it only translates a refusal the LIVE loader emitted for the
     * probed key back into a JSON type name. The admitted kind of every key
     * is still derived by probing validate() itself — never hand-copied.
     *
     * 'must be one of' reads as string because every enum door in
     * validate() guards a set of strings; that membership itself is pinned
     * separately by
     * {@see self::testEveryDocumentedEnumMatchesTheLoadersOwnAdmissionWords}.
     *
     * @var array<string,string>
     */
    private const KIND_REFUSALS = [
        'must be a non-empty string'     => 'string',
        'must contain non-empty strings' => 'string',
        'must be a list'                 => 'array',
        'must be an integer'             => 'integer',
        'must be a number'               => 'number',
        'must be an array'               => 'array',
        'must be an object'              => 'object',
        'must be a boolean'              => 'boolean',
        'must be a string'               => 'string',
        'must be one of'                 => 'string',
    ];

    /**
     * r1 HIGH-1 (M3 class), leg 1 — the TYPE column: every documented
     * property's `type` string is COMPARED to the kind the loader actually
     * admits, derived by probing validate() with an off-kind value and
     * reading the loader's own refusal word. Covers root properties, every
     * nested object sub-property (optional_blocks.*, report.*,
     * db_symbols.*, normalization.plugins), and the items-kind of every
     * documented list. Flipping any type string — victim or non-victim,
     * root or nested — flips this arm RED; presence-only is a defect, not
     * a posture.
     */
    public function testEveryDocumentedTypeMatchesTheLoadersAdmittedKind(): void
    {
        $schema = self::schema();
        $this->assertKindMirror($schema['properties'], static fn(array $p): array => $p, '', new ConfigLoader());
    }

    /**
     * @param array<string,mixed>        $props
     * @param callable(array<mixed>):array<string,mixed> $attach nests a sub-payload under the parent path
     */
    private function assertKindMirror(array $props, callable $attach, string $pathPrefix, ConfigLoader $loader): void
    {
        foreach ($props as $name => $definition) {
            self::assertIsArray($definition);
            $name    = (string)$name;
            $path    = $pathPrefix === '' ? $name : $pathPrefix . '.' . $name;
            $docType = $definition['type'] ?? null;
            self::assertIsString($docType, "$path: documented type missing");

            if ($docType === 'array' && isset($definition['items']['type']) && is_string($definition['items']['type'])) {
                $itemDoc  = $definition['items']['type'];
                $itemKind = self::doorKindOf(
                    $loader,
                    $attach([$name => [self::offKindProbeFor($itemDoc)]]),
                    $path,
                    self::offKindProbeFor($itemDoc)
                );
                self::assertSame(
                    $itemDoc,
                    $itemKind,
                    "docs/config-schema.json says $path items are of type '$itemDoc', but ConfigLoader::validate() admits '$itemKind' — the type column is a promise to schema consumers/IDEs; fix the doc or the admission, never let them disagree"
                );
            }

            $probe    = self::offKindProbeFor($docType);
            $doorKind = self::doorKindOf($loader, $attach([$name => $probe]), $path, $probe);
            self::assertSame(
                $docType,
                $doorKind,
                "docs/config-schema.json lies about the type column for '$path': doc claims '$docType' but "
                    . "validate() admits kind '$doorKind' for it — the type column is a promise to schema consumers/IDEs; fix the doc or the admission, never let them disagree"
            );

            if ($docType === 'object' && isset($definition['properties']) && is_array($definition['properties'])) {
                $this->assertKindMirror(
                    $definition['properties'],
                    static fn(array $child): array => $attach([$name => $child]),
                    $path,
                    $loader
                );
            }
        }
    }

    /**
     * A value a door admitting exactly $docType can never take. If the door
     * DOES take it, the doc/door pair already disagrees and the accept
     * branch of doorKindOf() reports the probe's own kind as the truth.
     */
    private static function offKindProbeFor(string $docType): int|string
    {
        return in_array($docType, ['string', 'boolean'], true) ? self::PROBE_INT : self::PROBE_STR;
    }

    /**
     * Ask the loader's own door what kind $path admits, by feeding it an
     * off-kind probe: acceptance or refusal wording is the evidence.
     *
     * @param array<string,mixed> $payload
     */
    private static function doorKindOf(ConfigLoader $loader, array $payload, string $path, int|string $probe): string
    {
        $refusal = null;
        try {
            $loader->validate($payload);
        } catch (\Throwable $e) {
            $refusal = $e->getMessage();
        }
        if ($refusal === null) {
            return is_int($probe) ? 'integer' : 'string';
        }
        if (str_contains($refusal, "Unknown config key '$path'")) {
            return 'refused-as-unknown';
        }
        self::assertStringStartsWith(
            $path . ' ',
            $refusal,
            "expected a refusal about '$path' itself, got: $refusal"
        );
        foreach (self::KIND_REFUSALS as $needle => $kind) {
            if (str_contains($refusal, $needle)) {
                return $kind;
            }
        }
        self::fail(
            "unclassifiable refusal for '$path': $refusal — the KIND_REFUSALS vocabulary needs a deliberate extension; "
            . 'shrugging this off would re-open the silent type-column drift r1 HIGH-1 caught (M3 class)'
        );
    }

    /**
     * r1 HIGH-1, leg 2 — the ENUM column: every documented enum list equals
     * the values validate() itself admits, read from the loader's own
     * "must be one of ..." refusal. Covers the root enum keys and the
     * db_symbols per-bucket value enum (both buckets), in both directions
     * (door-set equality + every documented member accepted).
     */
    public function testEveryDocumentedEnumMatchesTheLoadersOwnAdmissionWords(): void
    {
        $schema = self::schema();
        $loader = new ConfigLoader();

        foreach ($schema['properties'] as $name => $definition) {
            if (!is_array($definition) || !array_key_exists('enum', $definition)) {
                continue;
            }
            $name     = (string)$name;
            $docEnum  = $definition['enum'];
            self::assertIsArray($docEnum, "$name: enum must be a list");
            self::assertNotContains(self::PROBE_STR, $docEnum, "$name: probe collides with a documented enum value");

            $refusal = self::refusalOf($loader, [$name => self::PROBE_STR]);
            self::assertNotNull($refusal, "$name: the door accepted an outsider of the documented enum — it admits values the doc forbids");
            self::assertSame(
                self::sortedEnum(self::parseOneOfList($refusal, $name)),
                self::sortedEnum($docEnum),
                "$name: docs/config-schema.json enum differs from the values validate() itself admits ('$refusal')"
            );
            foreach ($docEnum as $value) {
                self::assertNull(
                    self::refusalOf($loader, [$name => $value]),
                    "$name: validate() refuses a value the doc promises ($value)"
                );
            }
        }

        $dbDoc = $schema['properties']['db_symbols']['properties'] ?? [];
        foreach (['methods', 'functions'] as $bucket) {
            self::assertIsArray($dbDoc, 'db_symbols sub-properties expected');
            $docEnum = $dbDoc[$bucket]['additionalProperties']['enum'] ?? null;
            self::assertIsArray($docEnum, "db_symbols.$bucket: value enum must be documented");
            $refusal = self::refusalOf($loader, ['db_symbols' => [$bucket => ['parity_probe_symbol' => self::PROBE_STR]]]);
            self::assertNotNull($refusal, "db_symbols.$bucket: the door accepted an op outside the documented enum");
            self::assertSame(
                self::sortedEnum($docEnum),
                self::sortedEnum(self::parseOneOfList($refusal, "db_symbols.$bucket.parity_probe_symbol")),
                "db_symbols.$bucket: documented op enum differs from what validate() admits ('$refusal')"
            );
            foreach ($docEnum as $op) {
                self::assertNull(
                    self::refusalOf($loader, ['db_symbols' => [$bucket => ['parity_probe_symbol' => $op]]]),
                    "db_symbols.$bucket: validate() refuses the documented op '$op'"
                );
            }
        }
    }

    /** @param array<int,array-key> $enum @return list<string> */
    private static function sortedEnum(array $enum): array
    {
        $names = array_map('strval', $enum);
        sort($names);
        return $names;
    }

    /**
     * Validate and return the refusal message, or null when the door
     * accepted the payload. No try/catch swallowing: acceptance and refusal
     * are both first-class evidence here.
     *
     * @param array<string,mixed> $payload
     */
    private static function refusalOf(ConfigLoader $loader, array $payload): ?string
    {
        try {
            $loader->validate($payload);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /** @return list<string> the admitted set carried by the refusal itself */
    private static function parseOneOfList(string $refusal, string $path): array
    {
        $at = strpos($refusal, 'must be one of ');
        self::assertNotFalse($at, "$path: refusal carries no admitted-value list: $refusal");
        return explode('|', substr($refusal, $at + strlen('must be one of ')));
    }

    /**
     * r1 HIGH-1, leg 3 — the DEFAULTS column, all 38+ roots and all 5+1
     * nested, not the 4/5 victims: every schema `default` equals the value
     * the REAL loader uses, derived from Config::defaults() with the key →
     * property routing extracted from ConfigLoader::load()'s own base-
     * defaults table and `?? $base->` legs. A default nobody pinned can no
     * longer drift silently — an unmapped defaulted key fails the arm
     * loudly ("extend the derivation"). (apply's default was presence-only
     * before this round; it is now value-pinned through the table, closing
     * the 4/5 gap.)
     */
    public function testEverySchemaDefaultEqualsTheDerivedLoaderDefault(): void
    {
        $schema = self::schema();
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Cli/ConfigLoader.php');
        $base   = Config::defaults([]);

        $rootProp = self::deriveRouting(
            $source,
            "~'([a-z_]+)'\s+=>\s+\\\$base->([A-Za-z]+),~",
            28,
            "the derivation lost its hook into ConfigLoader::load()'s base-defaults table"
        );
        foreach (self::deriveRouting(
            $source,
            "~\\\$data\['([a-z_]+)'\]\s*\?\?\s*\\\$base->([A-Za-z]+)~",
            4,
            'the derivation lost the `$data[\'k\'] ?? $base->prop` legs in load()'
        ) as $key => $prop) {
            $rootProp[$key] = $prop; // db_aware, trinity_collapse, ml_pair_url, ml_pair_allow_private
        }
        $optProp = self::deriveRouting(
            $source,
            "~\\\$optBlock\['([a-z_]+)'\]\s*\?\?\s*\\\$base->([A-Za-z]+)~",
            4,
            'the derivation lost the optional_blocks `?? $base->` legs in load()'
        );

        foreach ($schema['properties'] as $name => $definition) {
            self::assertIsArray($definition);
            $name = (string)$name;
            if (array_key_exists('default', $definition)) {
                self::assertSame(
                    $definition['default'],
                    self::derivedRootDefault($base, $rootProp, $source, $name),
                    "docs/config-schema.json default for '$name' is not the value the real loader uses"
                );
            }
            if (($definition['type'] ?? null) !== 'object' || !isset($definition['properties']) || !is_array($definition['properties'])) {
                continue;
            }
            foreach ($definition['properties'] as $sub => $subDef) {
                if (!is_array($subDef) || !array_key_exists('default', $subDef)) {
                    continue;
                }
                $sub  = (string)$sub;
                $path = "$name.$sub";
                if ($name === 'optional_blocks' && $sub === 'containment') {
                    // The file spells it nested, load() hoists/aliases it to
                    // the flat key; 'containment' has no root schema property
                    // of its own, so the root schema property is the routing
                    // source (shell-S24).
                    self::assertSame(
                        1,
                        preg_match("~\\\$flatData\['optional_blocks_containment'\]\s*=\s*\\\$optBlock\['containment'\];~", $source),
                        'the derivation lost the load() hoist line for optional_blocks.containment'
                    );
                    self::assertArrayHasKey('optional_blocks_containment', $rootProp, 'containment rides the flat table — extend the derivation');
                    $expected = $base->{$rootProp['optional_blocks_containment']};
                } elseif ($name === 'optional_blocks' && isset($optProp[$sub])) {
                    $expected = $base->{$optProp[$sub]};
                } elseif ($name === 'normalization' && $sub === 'plugins') {
                    // load(): `normalizationPlugins: $this->extractNormalizationPlugins($data)`
                    // — an absent section yields Config's default list.
                    $expected = $base->normalizationPlugins;
                } else {
                    self::fail("no derivation source for the nested default of '$path' — extend the derivation (this belt never lets a default go unpinned by silence)");
                }
                self::assertSame(
                    $subDef['default'],
                    $expected,
                    "docs/config-schema.json default for '$path' is not the value the real loader uses"
                );
            }
        }
    }

    /**
     * @return array<string,string> key => Config property
     */
    private static function deriveRouting(string $source, string $pattern, int $minCount, string $whyLost): array
    {
        $rows = [];
        self::assertGreaterThan(0, preg_match_all($pattern, $source, $rows, PREG_SET_ORDER), $whyLost);
        self::assertGreaterThanOrEqual($minCount, count($rows), $whyLost);
        $map = [];
        foreach ($rows as $row) {
            $map[$row[1]] = $row[2];
        }
        return $map;
    }

    /** @param array<string,string> $rootProp */
    private static function derivedRootDefault(Config $base, array $rootProp, string $source, string $name): mixed
    {
        if ($name === 'exclude_reset') {
            self::assertSame(
                1,
                preg_match("~\\\$data\['exclude_reset'\]\s*\?\?\s*false~", $source),
                "the derivation lost the load() default line for 'exclude_reset' (?? false) — extend it"
            );
            return false;
        }
        if ($name === 'normalization') {
            // The empty object {} decodes to [] in PHP; load() folds the
            // absent section through extractNormalizationPlugins() to the
            // stock (empty) plugins list.
            return $base->normalizationPlugins;
        }
        if ($name === 'accepted') {
            // Item 4.4: the root-only suppression register. No flat-map
            // leg, no CLI override — load() feeds the constructor directly
            // from $data; an absent key yields the empty list.
            self::assertSame(
                1,
                preg_match("~\\\$data\['accepted'\]\s*\?\?\s*\[\]~", $source),
                "the derivation lost the load() default line for 'accepted' (?? []) — extend it"
            );
            return [];
        }
        if (isset($rootProp[$name])) {
            return $base->{$rootProp[$name]};
        }
        self::fail("no derivation source for the default of '$name' — extend the derivation (this belt never lets a default go unpinned by silence)");
    }

    /**
     * r1 HIGH-1, leg 4 — the nested mirrors are CLOSED sets that the door
     * still enforces: every documented object property pins
     * additionalProperties:false, and a probe sub-key is refused by the
     * live loader. Over-admission drift (a new sub-key in the door, absent
     * from the doc) flips the outsider refusal missing → RED. The
     * normalization arm above (plugins_two) predates this round; it stays.
     */
    public function testNestedObjectMirrorsAreClosedSetsAndRefuseTheirOutsiders(): void
    {
        $schema   = self::schema();
        $loader   = new ConfigLoader();
        $outsider = '__phpdup_not_admitted__';

        foreach ($schema['properties'] as $name => $definition) {
            if (!is_array($definition) || ($definition['type'] ?? null) !== 'object') {
                continue;
            }
            $name = (string)$name;
            self::assertSame(
                false,
                $definition['additionalProperties'] ?? null,
                "docs/config-schema.json must keep '$name' a closed set (additionalProperties:false)"
            );
            self::assertIsArray($definition['properties'] ?? null, "object property '$name' must document its sub-key set");
            self::assertNotContains($outsider, array_keys($definition['properties']), "the outsider probe '$outsider' must stay undocumented");

            $refusal = self::refusalOf($loader, [$name => [$outsider => true]]);
            self::assertNotNull($refusal, "the loader admits '$outsider' under '$name' but the schema does not document it");
            self::assertStringContainsString(
                "Unknown config key '$name.$outsider'",
                $refusal,
                "expected the door to refuse the outsider under '$name' by path, got: $refusal"
            );
        }
    }
}
