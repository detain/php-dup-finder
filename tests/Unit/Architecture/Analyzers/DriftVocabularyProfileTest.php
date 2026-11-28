<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Architecture\Analyzers;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;
use Phpdup\Architecture\Analyzers\DriftAnalyzer;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\ProfileRegistry;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\LineRange;

/**
 * Item 4.1: the shipped profile vocabularies are live analyzer input.
 *
 * profiles/generic.json and profiles/db-aware-myadmin.json carry a
 * drift_flags block (predicates + sanitizer/auth/option names). The
 * profile TIER does not consume it — Command::profileToOverrides() maps
 * exactly its documented keys and drops the rest — so this test feeds
 * the decoded block straight into DriftAnalyzer and pins the data
 * end-to-end: a guard-drift cluster fires on the shipped vocabulary,
 * and the vocabulary the analyzer understands is exactly the shape the
 * profiles ship.
 */
final class DriftVocabularyProfileTest extends TestCase
{
    public function testGenericProfileShipsTheDriftVocabulary(): void
    {
        $flags = $this->genericFlags();

        self::assertSame(
            ['guard-drift', 'sanitizer-divergence', 'copy-paste-literal', 'odd-one-out'],
            $flags['predicates'],
            'the shipped predicate roster is the documented four'
        );
        foreach (['sanitizers', 'auth'] as $listKey) {
            self::assertIsList($flags[$listKey] ?? null, "{$listKey} must be a list of names");
            foreach ($flags[$listKey] as $name) {
                self::assertIsString($name);
                self::assertSame(strtolower($name), $name, "vocabulary name '{$name}' must be lower-cased (the analyzer compares call names lower-cased)");
            }
        }
        // Options keep verbatim constant spelling — they are compared to
        // the template's own name nodes, not to normalized call names.
        self::assertIsList($flags['options'] ?? null, 'options must be a list of names');
        foreach ($flags['options'] as $name) {
            self::assertIsString($name);
            self::assertNotSame('', $name);
        }
        self::assertContains('real_escape', $flags['sanitizers']);
        self::assertContains('require_auth', $flags['auth']);
    }

    public function testMyadminProfileAgreesOnThePredicateRoster(): void
    {
        $flags = ProfileRegistry::bundled()->load('db-aware-myadmin')['drift_flags'] ?? null;
        self::assertIsArray($flags, 'db-aware-myadmin must ship drift_flags too');

        self::assertSame($this->genericFlags()['predicates'], $flags['predicates']);
        self::assertContains('real_escape', $flags['sanitizers'],
            'the db_abstraction escape is the sanitizer of record for MyAdmin-shaped code');
    }

    public function testProfileVocabularyFiresTheAnalyzerOnAGuardCluster(): void
    {
        $cluster = $this->loadFixtureCluster();
        (new AntiUnifier(null, true, 3))->unify($cluster);

        $findings = (new DriftAnalyzer($this->genericFlags()))->analyze($cluster);

        self::assertCount(1, $findings);
        self::assertSame('drift-missing-guard', $findings[0]->code);
        self::assertStringContainsString('GuardMissing::handle', $findings[0]->message);
    }

    public function testTheBlockIsInertForTheConfigTier(): void
    {
        // Honest disclosure of the shipped-data posture: ProfileRegistry
        // hands the block to consumers, but the profile->Config mapping
        // (profileToOverrides) admits only its documented keys — a phpdup
        // run with --profile generic still analyses with zero drift
        // findings until the injection plumbing lands (follow-on row).
        $loaded = ProfileRegistry::bundled()->load('generic');
        self::assertArrayHasKey('drift_flags', $loaded);
        self::assertArrayHasKey('exclude', $loaded, 'the tier-consumed keys ride along unchanged');
    }

    /** @return array<string,mixed> */
    private function genericFlags(): array
    {
        $flags = ProfileRegistry::bundled()->load('generic')['drift_flags'] ?? null;
        self::assertIsArray($flags, 'generic profile must ship drift_flags');
        return $flags;
    }

    private function loadFixtureCluster(): Cluster
    {
        $members = [];
        foreach (glob(dirname(__DIR__, 4) . '/tests/Fixtures/drift/guard/*.php') ?: [] as $file) {
            $stmts  = (new AstParser())->parseCode(file_get_contents($file));
            self::assertNotNull($stmts);
            $class  = (new NodeFinder())->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);
            $method = (new NodeFinder())->findFirst($stmts, static fn(Node $n) => $n instanceof Node\Stmt\ClassMethod);
            self::assertInstanceOf(Node\Stmt\Class_::class, $class);
            self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);

            $block       = new Block(
                $file,
                new LineRange($method->getStartLine(), $method->getEndLine()),
                'method',
                null,
                (string) $class->name,
                $method->name->toString(),
                $method,
            );
            $block->id   = 'M:' . basename($file);
            $block->size = AstSerializer::nodeCount($method);
            $members[]   = $block;
        }
        self::assertCount(4, $members);
        return new Cluster('D:vocab', $members, 0.95, false);
    }
}
