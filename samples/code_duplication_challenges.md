# Duplicate Code That Is Hard To Detect

A duplicate-code detector can miss duplication for two broad reasons:

1. **Surface differences** — the code *looks* different syntactically.
2. **Structural/semantic differences** — the implementation shape changed even though behavior is effectively identical.

Below is a fairly exhaustive taxonomy used in clone-detection research, refactoring tooling, compiler normalization, and large-scale code intelligence systems.

---

# Complete Taxonomy of “Hidden” Duplicate Code

| #  | Type                                | What Changes                                | Why Simple Detectors Miss It         |
| -- | ----------------------------------- | ------------------------------------------- | ------------------------------------ |
| 1  | Whitespace / Formatting Differences | Indentation, spacing, line wrapping         | Text differs byte-for-byte           |
| 2  | Comment Differences                 | Different comments/docblocks                | Token streams differ                 |
| 3  | Identifier Renaming                 | Variable/function/class names               | Tokens differ semantically identical |
| 4  | Literal Value Changes               | Constants/strings/numbers changed           | Looks like different business logic  |
| 5  | Type Annotation Differences         | Types/docs/generics differ                  | AST differs slightly                 |
| 6  | Parameter Reordering                | Same logic, arguments reordered             | Token order changes                  |
| 7  | Statement Reordering                | Independent statements rearranged           | Sequence mismatch                    |
| 8  | Expression Rewriting                | Equivalent math/logic expressed differently | AST shape differs                    |
| 9  | Control Structure Substitution      | `if` vs ternary vs guard clause             | Structural mismatch                  |
| 10 | Loop Form Changes                   | `for` vs `foreach` vs `while`               | Different control nodes              |
| 11 | Inlined vs Extracted Logic          | Helper extracted in one place only          | One side flattened                   |
| 12 | Different API Usage                 | Alternate APIs producing same outcome       | Semantic equivalence hidden          |
| 13 | Different Data Structures           | Array vs object vs collection               | Access patterns differ               |
| 14 | Boolean Logic Transformations       | DeMorgan, negation inversions               | Condition trees differ               |
| 15 | Early Return vs Nested Blocks       | Guard clauses vs nesting                    | CFG differs                          |
| 16 | Exception vs Conditional Handling   | Throw/catch vs error codes                  | Different flow style                 |
| 17 | Polymorphic Variants                | OO override vs switch statement             | Behavior distributed                 |
| 18 | Functional vs Imperative Style      | `array_map` vs loops                        | AST completely different             |
| 19 | SQL/String Template Variants        | Query constructed differently               | Dynamic string assembly              |
| 20 | Macro/Metaprogramming Expansion     | Generated code vs handwritten               | Source not normalized                |
| 21 | Configuration-Driven Duplication    | Logic encoded in config                     | Behavioral duplication hidden        |
| 22 | Copy-Paste With Small Edits         | Tiny mutations after copy                   | Near-clone threshold exceeded        |
| 23 | Dead-Code Noise                     | Extra unused lines inserted                 | Similarity diluted                   |
| 24 | Logging/Instrumentation Noise       | Metrics/debug statements added              | Token interleaving                   |
| 25 | Framework Boilerplate Noise         | Annotation/decorator noise                  | Framework syntax dominates           |
| 26 | Namespace / Import Differences      | Fully-qualified vs imported names           | Different tokens                     |
| 27 | Order of Helper Calls               | Same transformations different order        | Semantically equivalent sometimes    |
| 28 | Split vs Combined Conditions        | Multiple ifs vs compound condition          | CFG divergence                       |
| 29 | Data Normalization Variants         | Trim/lowercase/sanitize order changes       | Preprocessing differs                |
| 30 | Async vs Sync Implementations       | Callback/promise/sync                       | Different execution model            |
| 31 | Recursive vs Iterative              | Same algorithm differently structured       | Shape mismatch                       |
| 32 | Table-Driven vs Hardcoded Logic     | Data lookup vs condition chain              | Logic hidden in data                 |
| 33 | State Machine Encoding Differences  | Explicit state vars vs branching            | Behavioral equivalence only          |
| 34 | DSL/Template Indirection            | Templating hides duplication                | Runtime-generated similarity         |
| 35 | Partial Duplication                 | Only fragments duplicated                   | Below threshold                      |
| 36 | Cross-Language Duplication          | Same logic in JS/PHP/Python                 | Language syntax differs              |
| 37 | Semantic Equivalence                | Different algorithms same behavior          | Requires deep analysis               |
| 38 | Algebraic Equivalence               | Math transforms preserve meaning            | Needs symbolic reasoning             |
| 39 | Commutative Operations              | Order-independent operations                | Tree ordering differs                |
| 40 | Optional Step Variants              | Same pipeline + extra steps                 | Similarity fragmented                |
| 41 | Feature-Flag Divergence             | Conditional feature branches                | Clone hidden inside flags            |
| 42 | Object-Oriented Decomposition       | Same behavior split across classes          | No contiguous duplicate block        |
| 43 | Generated Builder Patterns          | Fluent APIs rearranged                      | Equivalent output                    |
| 44 | Different Error Handling Style      | Return null vs exceptions                   | Structural divergence                |
| 45 | Serialization Differences           | JSON/array/object conversions               | Representation differs               |
| 46 | Different Naming Conventions        | snake_case vs camelCase                     | Token mismatch                       |
| 47 | Localization Differences            | Same logic different messages               | Strings dominate tokens              |
| 48 | Security/Sanitization Noise         | Escaping/auth checks inserted               | Functional core obscured             |
| 49 | Dependency Injection Variants       | Constructor/service locator/global          | Access pattern differs               |
| 50 | Temporal Coupling Variants          | Same steps different timing                 | Ordering changes semantics slightly  |
| 51 | ORM vs Raw SQL                      | Same DB behavior different layers           | Different abstraction levels         |
| 52 | Event-Driven vs Direct Calls        | Event dispatch instead of call              | Control flow indirect                |
| 53 | Different Null Handling             | Nullsafe/coalesce/if checks                 | AST divergence                       |
| 54 | Encoding/Parsing Variants           | Regex vs parser vs explode                  | Same extraction behavior             |
| 55 | Multi-Step Refactoring Drift        | Copies evolve independently                 | Clone genealogy hidden               |
| 56 | Platform Conditional Code           | OS/env-specific branches                    | Similarity fragmented                |
| 57 | Generic Abstraction Drift           | Templates/generics hide sameness            | Indirection layers                   |
| 58 | Copy-Paste Across Files             | Fragmented distribution                     | Local-only detectors fail            |
| 59 | Behavioral Duplication              | Same side effects, different code           | Requires runtime/semantic analysis   |
| 60 | Domain-Level Duplication            | Same business rule everywhere               | Hidden by implementation details     |

---

# Breakdown of Each Type With PHP Examples

---

# 1. Identifier Renaming

## Duplicate Variants

```php
$total = $price * $qty;
echo $total;
```

```php
$amount = $unitPrice * $count;
echo $amount;
```

## Refactored

```php
function calculateTotal(float $price, int $quantity): float
{
    return $price * $quantity;
}

echo calculateTotal($price, $qty);
echo calculateTotal($unitPrice, $count);
```

---

# 2. Literal Value Changes

## Duplicate Variants

```php
if ($age >= 18) {
    echo "adult";
}
```

```php
if ($age >= 21) {
    echo "adult";
}
```

## Refactored

```php
function isAdult(int $age, int $minimumAge): bool
{
    return $age >= $minimumAge;
}
```

---

# 3. Statement Reordering

## Duplicate Variants

```php
trim($name);
strtolower($name);
```

```php
strtolower($name);
trim($name);
```

## Refactored

```php
function normalizeName(string $name): string
{
    return strtolower(trim($name));
}
```

---

# 4. Control Structure Substitution

## Duplicate Variants

```php
if ($loggedIn) {
    return true;
}

return false;
```

```php
return $loggedIn ? true : false;
```

## Refactored

```php
function isLoggedIn(bool $loggedIn): bool
{
    return $loggedIn;
}
```

---

# 5. Loop Form Changes

## Duplicate Variants

```php
for ($i = 0; $i < count($items); $i++) {
    echo $items[$i];
}
```

```php
foreach ($items as $item) {
    echo $item;
}
```

## Refactored

```php
function printItems(array $items): void
{
    foreach ($items as $item) {
        echo $item;
    }
}
```

---

# 6. Expression Rewriting

## Duplicate Variants

```php
if (($a + $b) > 10) {
    echo "high";
}
```

```php
if ($a > (10 - $b)) {
    echo "high";
}
```

## Refactored

```php
function isHigh(int $a, int $b): bool
{
    return ($a + $b) > 10;
}
```

---

# 7. Boolean Logic Transformation

## Duplicate Variants

```php
if (!($user === null)) {
    echo "exists";
}
```

```php
if ($user !== null) {
    echo "exists";
}
```

## Refactored

```php
function userExists($user): bool
{
    return $user !== null;
}
```

---

# 8. Early Return vs Nested Condition

## Duplicate Variants

```php
if ($user) {
    if ($user->active) {
        return true;
    }
}

return false;
```

```php
if (!$user) {
    return false;
}

if (!$user->active) {
    return false;
}

return true;
```

## Refactored

```php
function canLogin($user): bool
{
    return $user && $user->active;
}
```

---

# 9. Functional vs Imperative

## Duplicate Variants

```php
$result = [];

foreach ($numbers as $n) {
    $result[] = $n * 2;
}
```

```php
$result = array_map(fn($n) => $n * 2, $numbers);
```

## Refactored

```php
function doubleNumbers(array $numbers): array
{
    return array_map(fn($n) => $n * 2, $numbers);
}
```

---

# 10. Different APIs Same Behavior

## Duplicate Variants

```php
$json = json_encode($data);
```

```php
$json = serialize($data);
```

## Refactored

```php
function encodeData(mixed $data, string $format): string
{
    return match ($format) {
        'json' => json_encode($data),
        'serialize' => serialize($data),
    };
}
```

---

# 11. Inlined vs Extracted Logic

## Duplicate Variants

```php
$email = trim(strtolower($email));
```

```php
$email = normalizeEmail($email);
```

## Refactored

```php
function normalizeEmail(string $email): string
{
    return trim(strtolower($email));
}
```

---

# 12. Different Data Structures

## Duplicate Variants

```php
$name = $user['name'];
```

```php
$name = $user->name;
```

## Refactored

```php
function getUserName($user): string
{
    return is_array($user)
        ? $user['name']
        : $user->name;
}
```

---

# 13. Exception vs Conditional Handling

## Duplicate Variants

```php
if (!$file) {
    return null;
}
```

```php
if (!$file) {
    throw new RuntimeException("Missing file");
}
```

## Refactored

```php
function validateFile($file, bool $throw = false)
{
    if ($file) {
        return $file;
    }

    if ($throw) {
        throw new RuntimeException("Missing file");
    }

    return null;
}
```

---

# 14. Recursive vs Iterative

## Duplicate Variants

```php
function sumRecursive(array $items): int
{
    if (!$items) {
        return 0;
    }

    return array_shift($items) + sumRecursive($items);
}
```

```php
$total = 0;

foreach ($items as $item) {
    $total += $item;
}
```

## Refactored

```php
function sumItems(array $items): int
{
    return array_sum($items);
}
```

---

# 15. ORM vs Raw SQL

## Duplicate Variants

```php
$user = User::where('id', $id)->first();
```

```php
$user = $pdo->query("SELECT * FROM users WHERE id = $id")->fetch();
```

## Refactored

```php
function findUserById(int $id)
{
    return User::where('id', $id)->first();
}
```

---

# 16. Split vs Combined Conditions

## Duplicate Variants

```php
if ($a) {
    if ($b) {
        run();
    }
}
```

```php
if ($a && $b) {
    run();
}
```

## Refactored

```php
function shouldRun(bool $a, bool $b): bool
{
    return $a && $b;
}
```

---

# 17. Null Handling Variants

## Duplicate Variants

```php
$name = $user ? $user->name : null;
```

```php
$name = $user?->name;
```

## Refactored

```php
function getName($user): ?string
{
    return $user?->name;
}
```

---

# 18. Table-Driven vs Condition Chains

## Duplicate Variants

```php
if ($status === 'new') {
    return 'blue';
}

if ($status === 'done') {
    return 'green';
}
```

```php
$map = [
    'new' => 'blue',
    'done' => 'green',
];

return $map[$status];
```

## Refactored

```php
function statusColor(string $status): string
{
    return [
        'new' => 'blue',
        'done' => 'green',
    ][$status] ?? 'gray';
}
```

---

# 19. Logging Noise

## Duplicate Variants

```php
Logger::info("saving");

saveUser($user);
```

```php
saveUser($user);
```

## Refactored

```php
function persistUser($user, bool $log = false): void
{
    if ($log) {
        Logger::info("saving");
    }

    saveUser($user);
}
```

---

# 20. Cross-Layer Duplication

## Duplicate Variants

```php
if ($price > 100) {
    $discount = 10;
}
```

```php
$data['discount'] = $price > 100 ? 10 : 0;
```

## Refactored

```php
function calculateDiscount(float $price): int
{
    return $price > 100 ? 10 : 0;
}
```

---

# Major Categories Used In Clone Detection Research

These are the canonical clone classes used academically and in industrial tooling:

| Clone Type   | Meaning                               |
| ------------ | ------------------------------------- |
| Type-1 Clone | Exact copy except whitespace/comments |
| Type-2 Clone | Renamed identifiers/literals          |
| Type-3 Clone | Statements added/removed/changed      |
| Type-4 Clone | Semantic equivalence only             |

Type-4 semantic clones are the hardest problem in duplicate detection and usually require combinations of:

* AST normalization
* CFG analysis
* SSA transformation
* Symbolic execution
* Dataflow analysis
* Embedding/vector similarity
* ML/LLM semantic comparison
* Runtime behavior analysis

---

# What Mature Duplicate Detectors Normalize

Advanced detectors often normalize:

| Normalization               | Purpose                      |
| --------------------------- | ---------------------------- |
| Remove whitespace/comments  | Ignore formatting            |
| Identifier canonicalization | `x`,`y`,`foo` → placeholders |
| Literal abstraction         | Constants normalized         |
| AST canonicalization        | Structural equivalence       |
| Commutative reordering      | `a+b == b+a`                 |
| Control-flow normalization  | Guard vs nested logic        |
| Dead-code elimination       | Ignore noise                 |
| API abstraction             | Equivalent library calls     |
| Inline expansion            | Compare extracted vs inline  |
| CFG comparison              | Behavioral similarity        |
| Semantic embeddings         | Meaning similarity           |

---

# Why “Duplicate” Is Often Actually “Same Business Rule”

The hardest class is domain duplication:

```php
if ($customer->age >= 18)
```

somewhere else:

```php
if ($user->canLegallyPurchase())
```

somewhere else:

```php
if (!$minor)
```

These may all encode the same policy, but syntactically they are unrelated.

That requires:

* ontology understanding
* naming semantics
* domain rule extraction
* symbolic reasoning
* business capability mapping

which is why modern AI-assisted clone detection is increasingly combining:

* static analysis
* graph analysis
* embeddings
* LLM semantic reasoning
* execution traces
* architectural context
