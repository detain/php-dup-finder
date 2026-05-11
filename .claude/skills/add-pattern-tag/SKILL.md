---
name: add-pattern-tag
description: Adds a new pattern-recognizer tag to phpdup's refactor stage by implementing a private detect method on src/Refactor/PatternRecognizer.php, registering it in tag(), and adding a matching scenario in tests/Unit/Refactor/PatternRecognizerTest.php. Use when the user says 'add pattern tag', 'recognise <archetype>', 'tag clusters as <pattern>', 'detect <framework idiom>', or wants a new structural/domain/framework archetype added to the existing list (sql-builder, controller-action, eloquent-model, db-op, …). Capabilities: AST-shape inspection via PhpParser\NodeFinder over Block->ast, hole-kind inspection over Cluster->holes, fully-qualified-name/path matching on Block->class/->namespace/->file, $m->ast === null guards for unloaded ASTs, tag dedup via the tag() loop. Do NOT use for: architectural findings (those live in src/Architecture/Analyzers/), CLI flags to filter tags (none exist — tags are always emitted on Cluster->patternTags), modifying signature output (that's src/Refactor/SignatureBuilder.php), or clustering thresholds (Clusterer.php).
paths:
  - src/Refactor/PatternRecognizer.php
  - tests/Unit/Refactor/PatternRecognizerTest.php
---
# add-pattern-tag

## Critical

- **Pattern tags are advisory.** They never change clustering, scoring, or signature output — they are a `string[]` label list appended to `Cluster->patternTags` and rendered by reporters. Do NOT thread a CLI flag through `src/Cli/Command.php` or `src/Cli/Config.php` to gate emission; the existing tags are always emitted.
- **AST may be missing.** `Block->ast` can be `null` for members whose AST was unloaded after preprocessing. Every NodeFinder-based detector MUST guard `if ($m->ast === null) continue;` (see `isLoopMap`, `isLoopFilter`, `isDbOp` in `src/Refactor/PatternRecognizer.php`). Class-name / file-path / member-name detectors do NOT need the guard since they read `$m->class`, `$m->file`, `$m->name`, `$m->namespace` directly.
- **PHP-Parser 5 quirks.** `Node\Stmt\Throw_` does NOT exist — use `$stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Throw_` (mirror the comment at `PatternRecognizer::isValidationChain`). For migrations to PHP-Parser 5 patterns, always inspect a `Node\Identifier` via `->name` (a string), not by string-casting the Identifier node.
- **Strict types, final class.** `PatternRecognizer` is `final` with `declare(strict_types=1);` at file top. New detectors are `private` and return `bool` (no nullable `:?bool`, no `: ?something` returns — see the PHP 8.1 floor in `CLAUDE.md`).
- **No widening to `tests/Fixtures/`.** Psalm and PHPStan are scoped to `src/` only. Your detector must pass both at level 6 with no new `psalm-baseline.xml` entries — fix issues, do not baseline them.
- **Run the existing suite before claiming done.** `vendor/bin/phpunit --testsuite Unit` must stay green (note: `failOnWarning` and `failOnNotice` are ON in `phpunit.xml` — any deprecation fails the build).

## Instructions

### Step 1 — Pick the tag name and category

Tag names are lowercase, hyphenated, singular nouns (`sql-builder`, `controller-action`, `db-op`, `loop-map`). Categorize:

- **Hole-shape tag** (e.g. `config-driven`, `strategy`, `optional-segments`): inspects `Cluster->holes` only — no AST traversal needed.
- **Member-metadata tag** (e.g. `controller-action`, `migration`, `repository-method`, `event-listener`, `service-provider`, `eloquent-model`): inspects `$m->class`, `$m->namespace`, `$m->file`, `$m->name` — no AST needed, no null-guard needed.
- **AST-shape tag** (e.g. `sql-builder`, `loop-map`, `error-handler`, `builder-chain`, `db-op`): uses `PhpParser\NodeFinder` on `$m->ast` — REQUIRES `if ($m->ast === null) continue;`.

Verify before proceeding: confirm the new tag is NOT already an architectural finding (those live under `src/Architecture/Analyzers/` and emit `Finding` objects, not `patternTags`).

### Step 2 — Add the detector method to `src/Refactor/PatternRecognizer.php`

Append a `private` method named `is<TagCamelCase>` (e.g. `isMessageHandler`, `isFactoryMethod`) at the end of the class, alongside existing detectors. Boilerplate template (pick the one matching your category):

**Member-metadata template** — model after `isRepositoryMethod` in `src/Refactor/PatternRecognizer.php`:

```php
/**
 * One-line description matching the existing docblock style.
 */
private function isMyTag(Cluster $cluster): bool
{
    foreach ($cluster->members as $m) {
        $cls = (string)$m->class;
        if (!str_ends_with($cls, 'MySuffix')) continue;
        $name = strtolower((string)$m->name);
        if (str_starts_with($name, 'verb')) return true;
    }
    return false;
}
```

**AST-shape template** — model after `isLoopMap` in `src/Refactor/PatternRecognizer.php`:

```php
/**
 * One-line description matching the existing docblock style.
 */
private function isMyTag(Cluster $cluster): bool
{
    $finder = new NodeFinder();
    foreach ($cluster->members as $m) {
        if ($m->ast === null) continue;
        $hits = $finder->findInstanceOf([$m->ast], Node\Stmt\Foreach_::class);
        foreach ($hits as $node) {
            if (/* shape predicate */) return true;
        }
    }
    return false;
}
```

**Hole-shape template** — model after `isStrategy` in `src/Refactor/PatternRecognizer.php`:

```php
private function isMyTag(Cluster $cluster): bool
{
    if (count($cluster->holes) !== 1) return false;
    return $cluster->holes[0]->kind === 'call';
}
```

Verify before proceeding: method name matches `is<CamelCase>`, returns `bool`, is `private`, no docblock for `@return` (existing methods omit it), inline comment describes the heuristic.

### Step 3 — Register in the `tag()` loop

Edit `tag()` in `src/Refactor/PatternRecognizer.php`. Append ONE line in the appropriate group (alphabetical inside the group is NOT required; preserve grouping):

- Hole-shape detectors go in the first block (after `hasOptionalSegments`).
- Framework-aware detectors go in the IX.A block (after `isQueryBuilderChain`).
- Domain-pattern detectors go in the I.A.2-7 block (after `isDbOp`).

Exact line format — match the column alignment of neighbours:

```php
if ($this->isMyTag($cluster))            $tags[] = 'my-tag';
```

The spaces between `)` and `$tags[]` align the assignments visually. Do not add a `return` or break — every detector runs unconditionally and tags accumulate.

Verify before proceeding: grepping for `'my-tag'` in `src/Refactor/PatternRecognizer.php` shows exactly one match (the new line in `tag()`); the detector method itself does not reference the tag string.

### Step 4 — Add a test in `tests/Unit/Refactor/PatternRecognizerTest.php`

The test file lives at `tests/Unit/Refactor/PatternRecognizerTest.php`. Namespace: `Phpdup\Tests\Unit\Refactor`. Class is `final class PatternRecognizerTest extends TestCase`.

Add ONE positive test (required) and ONE absence test (recommended). Choose helper based on category:

**Hole-shape test** — model after `testStrategyTagWhenSingleNameHole`:

```php
public function testMyTagWhenSomeCondition(): void
{
    $cluster = new Cluster('TEST', $this->dummyMembers(), 1.0, false);
    $cluster->holes = [
        new Hole('__P0', 'call', ['fooBar()', 'bazQux()']),
    ];
    (new PatternRecognizer())->tag($cluster);
    $this->assertContains('my-tag', $cluster->patternTags);
}
```

**Member-metadata test** — model after `testRepositoryMethodTag`:

```php
public function testMyTagFromClassSuffix(): void
{
    $blocks = $this->dummyMembers('verbSomething');
    foreach ($blocks as $b) {
        $b->class = 'AcmeMySuffix';
    }
    $cluster = new Cluster('TEST', $blocks, 1.0, false);
    (new PatternRecognizer())->tag($cluster);
    $this->assertContains('my-tag', $cluster->patternTags);
}
```

**AST-shape test** — model after `testLoopMapTag` using `blocksFromCode()`:

```php
public function testMyTagFromAstShape(): void
{
    $blocks = $this->blocksFromCode('<?php function f() { /* code that triggers detector */ }');
    $cluster = new Cluster('TEST', [$blocks[0], $blocks[0]], 1.0, false);
    (new PatternRecognizer())->tag($cluster);
    $this->assertContains('my-tag', $cluster->patternTags);
}
```

Use the existing helpers — do NOT roll your own `Block` constructor unless you need a synthetic AST attribute (then mirror `blockWithDbCall` in the test file). `dummyMembers()` returns two identical blocks with a parsed AST so NodeFinder-based detectors never null-guard out.

Verify before proceeding: test class names, namespace, and `TestCase` base class match the file; no new imports beyond those already at the top of `PatternRecognizerTest.php`.

### Step 5 — Run static analysis and tests

Run all three from the repo root, sequentially. ANY failure must be fixed in `src/Refactor/PatternRecognizer.php` — do NOT add to `psalm-baseline.xml`:

```bash
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpstan analyse
vendor/bin/psalm
```

Verify before claiming done: all three exit 0. If `failOnNotice` triggers from a PhpParser deprecation, fix the API usage (typically a `Node\Identifier::$name` string cast).

### Step 6 — Update documentation if user-facing

If the tag will appear in CLI / HTML output users see, briefly mention it in the docblock at the top of `PatternRecognizer` (the `Implemented patterns` list in `src/Refactor/PatternRecognizer.php`). Keep it to one line in the same indentation style. Do not edit `docs/CLI.md` (no flag exists) or `docs/config-schema.json` (no schema field exists).

## Examples

### Example 1 — User asks to tag dispatcher classes

**User says:** "Add a pattern tag `message-handler` for classes whose name ends in `Handler` and whose member is `__invoke` or `handle`."

**Actions taken:**

1. This is a member-metadata tag — no NodeFinder, no `$m->ast` guard.
2. Add `isMessageHandler()` at end of `src/Refactor/PatternRecognizer.php`:
   ```php
   private function isMessageHandler(Cluster $cluster): bool
   {
       foreach ($cluster->members as $m) {
           $cls = (string)$m->class;
           if (!str_ends_with($cls, 'Handler')) continue;
           $name = (string)$m->name;
           if ($name === '__invoke' || $name === 'handle') return true;
       }
       return false;
   }
   ```
3. Register in `tag()` next to `isEventListener`:
   ```php
   if ($this->isMessageHandler($cluster))       $tags[] = 'message-handler';
   ```
4. Add `testMessageHandlerTag()` in `tests/Unit/Refactor/PatternRecognizerTest.php` mirroring `testEventListenerTag`.
5. Run `vendor/bin/phpunit --testsuite Unit && vendor/bin/phpstan analyse && vendor/bin/psalm`.

**Result:** Clusters whose first member is `App\MessageHandler\SendInvoiceHandler::__invoke` now include `message-handler` in `$cluster->patternTags`, rendered by `CliReporter::renderTags` and surfaced in HTML/JSON reports.

### Example 2 — User asks to recognise factory methods via AST shape

**User says:** "Recognise factory methods — a static method whose body is a single `return new SomeClass(...)`."

**Actions taken:**

1. AST-shape tag. Add `isFactoryMethod()` modeled after `isLoopMap`:
   ```php
   private function isFactoryMethod(Cluster $cluster): bool
   {
       foreach ($cluster->members as $m) {
           if ($m->ast === null) continue;
           if (!$m->ast instanceof Node\Stmt\ClassMethod) continue;
           if (!$m->ast->isStatic()) continue;
           $stmts = $m->ast->stmts ?? [];
           if (count($stmts) !== 1) continue;
           $only = $stmts[0];
           if ($only instanceof Node\Stmt\Return_ && $only->expr instanceof Node\Expr\New_) {
               return true;
           }
       }
       return false;
   }
   ```
2. Register in the I.A.2-7 block of `tag()`.
3. Add `testFactoryMethodTag()` using `blocksFromCode()` with `<?php class F { public static function make(): self { return new self(); } }` — note: `blocksFromCode` returns method blocks too; assert `assertNotEmpty($blocks)`.
4. Run unit tests + phpstan + psalm.

**Result:** Static factory methods are tagged `factory-method` in cluster reports.

## Common Issues

**"Call to undefined method PhpParser\Node\Identifier::toString()"**
— `Node\Identifier` exposes `->name` (a `string`), not `->toString()`. Use `$node->name->name` for `MethodCall` / `ClassMethod`, and `$node->name->toString()` only for `Node\Name` (FQNs / function calls). See the split in `isSqlBuilder` in `src/Refactor/PatternRecognizer.php`.

**"Class PhpParser\Node\Stmt\Throw_ not found"**
— PHP-Parser 5 removed `Stmt\Throw_`. Match the throw via `$stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Throw_` (see `isValidationChain` in `src/Refactor/PatternRecognizer.php`).

**PHPStan: "Access to an undefined property PhpParser\Node::$ast"**
— You're treating an arbitrary `Node` like a `Block`. `$m` in `foreach ($cluster->members as $m)` is a `Phpdup\Extraction\Block`, and `$m->ast` is `?\PhpParser\Node` (nullable). The properties are `$m->ast`, `$m->class`, `$m->name`, `$m->file`, `$m->namespace`, `$m->kind`, `$m->range`, `$m->canonical`.

**PHPUnit: "Test failed: assertContains 'my-tag' not in patternTags"**
— Three failure modes: (1) you forgot to register the detector in `tag()` — grepping for `'my-tag'` in `src/Refactor/PatternRecognizer.php` must show the registration line; (2) your detector returns `false` because `$m->ast === null` — `dummyMembers()` always parses an AST so this is rare, but check `blocksFromCode()` actually returned blocks (`assertNotEmpty($blocks)`); (3) the test sets `$b->class` on a block whose `$m->name` doesn't match — re-read the `dummyMembers($name)` parameter in the test helpers.

**phpunit `failOnNotice` fails on a deprecation from PhpParser**
— You're using a removed Node accessor. Run `vendor/bin/phpunit --testsuite Unit --display-deprecations` to see the exact deprecation and fix the call site in the detector. Do not silence with `@` or `error_reporting()` — `phpunit.xml` will still fail.

**Psalm: "PossiblyNullPropertyFetch on Block::$ast"**
— Add the null guard `if ($m->ast === null) continue;` as the first line of the per-member loop. This is mandatory for AST-shape detectors.

**Tag appears twice in `patternTags`**
— You added the registration line in two groups inside `tag()`. The list is built by appending unconditionally; there is no dedup. Remove the duplicate registration.
