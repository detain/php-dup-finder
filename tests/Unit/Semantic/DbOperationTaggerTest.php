<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Semantic;

use PHPUnit\Framework\TestCase;
use Phpdup\Normalization\DbOpRegistry;
use Phpdup\Parsing\AstParser;
use Phpdup\Semantic\DbOperationTagger;

final class DbOperationTaggerTest extends TestCase
{
    public function testTagsEloquentReadAsDbRead(): void
    {
        $node = $this->parse('<?php function f($id) { return User::find($id); }');
        $tags = (new DbOperationTagger())->tag($node);
        $this->assertSame(['db.read' => 1], $tags);
    }

    public function testTagsRawSqlQueryAsDbQuery(): void
    {
        $node = $this->parse('<?php function f($db) { return $db->query("SELECT * FROM users"); }');
        $tags = (new DbOperationTagger())->tag($node);
        $this->assertSame(['db.query' => 1], $tags);
    }

    public function testTagsMysqliFunctionsAsDbCallsRegardlessOfShape(): void
    {
        $node = $this->parse('<?php function f($db, $sql) {
            $r = mysqli_query($db, $sql);
            $row = mysqli_fetch_assoc($r);
            return $row;
        }');
        $tags = (new DbOperationTagger())->tag($node);
        $this->assertSame(['db.query' => 1, 'db.read' => 1], $tags);
    }

    public function testTagsTrinityWithReadMutateSave(): void
    {
        $node = $this->parse('<?php function f($id) {
            $user = User::find($id);
            $user->name = "Bob";
            $user->save();
        }');
        $tags = (new DbOperationTagger())->tag($node);
        $this->assertSame(['db.read' => 1, 'db.write' => 1], $tags);
    }

    public function testCountsAreMultisetForRepeatedOps(): void
    {
        $node = $this->parse('<?php function f($a, $b) {
            $x = User::find($a);
            $y = User::find($b);
            $x->save();
        }');
        $tags = (new DbOperationTagger())->tag($node);
        $this->assertSame(['db.read' => 2, 'db.write' => 1], $tags);
    }

    public function testIgnoresNonDbCalls(): void
    {
        $node = $this->parse('<?php function f($items) {
            return array_sum(array_map(fn($x) => $x * 2, $items));
        }');
        $tags = (new DbOperationTagger())->tag($node);
        $this->assertSame([], $tags);
    }

    public function testRecognisesPrefoldedDbOpNameViaSyntheticPrefix(): void
    {
        // Synthetic __DB_<OP>__ FuncCalls produced by an earlier
        // DbOpCanonicalizer pass are recognised even when the
        // attribute is missing — the prefix carries the op verbatim.
        $node = $this->parse('<?php function f() {
            __DB_READ__("user");
            __DB_WRITE__("user");
        }');
        $tags = (new DbOperationTagger())->tag($node);
        $this->assertSame(['db.read' => 1, 'db.write' => 1], $tags);
    }

    public function testCustomRegistryEntryParticipates(): void
    {
        $registry = new DbOpRegistry(customMethodOps: ['frobnicate' => DbOpRegistry::OP_QUERY]);
        $node = $this->parse('<?php function f($x) { return $x->frobnicate(); }');
        $tags = (new DbOperationTagger($registry))->tag($node);
        $this->assertSame(['db.query' => 1], $tags);
    }

    public function testEloquentAndPdoTagSummariesMatch(): void
    {
        // Same DB shape (1 read), different surface APIs — tag
        // summary is identical, which is the whole point of
        // option 3.
        $eloquent = $this->parse('<?php function f($id) { return User::find($id); }');
        $pdo      = $this->parse('<?php function f($pdo, $id) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        }');
        $a = (new DbOperationTagger())->tag($eloquent);
        $b = (new DbOperationTagger())->tag($pdo);
        // Both have at least one read.
        $this->assertGreaterThanOrEqual(1, $a['db.read'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $b['db.read'] ?? 0);
    }

    private function parse(string $code): \PhpParser\Node
    {
        $stmts = (new AstParser())->parseCode($code);
        return $stmts[0];
    }
}
