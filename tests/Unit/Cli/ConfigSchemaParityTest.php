<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

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
}
