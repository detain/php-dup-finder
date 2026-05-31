| Duplication Type               | Short Description                                                                    |
| ------------------------------ | ------------------------------------------------------------------------------------ |
| Data Duplication               | Repeated constants, literals, configuration values, or raw data across the codebase. |
| Type Duplication               | Similar logic implemented separately for different data types/classes.               |
| Algorithm Duplication          | Repeated computational logic or processing algorithms with minor variations.         |
| Structural Duplication         | Repeated control-flow or architectural structure even when logic differs slightly.   |
| Semantic Duplication           | Different code expressing the same business meaning or rule.                         |
| Temporal Duplication           | Operations that must always occur together or in a fixed sequence.                   |
| Knowledge Duplication          | The same business fact or rule represented in multiple places/systems.               |
| Representation Duplication     | Multiple nearly-identical models, DTOs, schemas, or data representations.            |
| Behavioral Duplication         | Different implementations producing the same observable behavior.                    |
| UI Duplication                 | Repeated interface layouts, components, interaction logic, or styling patterns.      |
| Configuration Duplication      | Repeated infrastructure, deployment, runtime, or application configuration.          |
| Test Duplication               | Repeated test setup, fixtures, assertions, or validation logic.                      |
| Query Duplication              | Repeated SQL, ORM queries, filters, or data-access patterns.                         |
| Protocol Duplication           | Repeated handling logic for APIs, transports, retries, auth, or serialization.       |
| Copy-Paste Duplication         | Directly copied source code with little or no modification.                          |
| Textual Duplication            | Similar or identical raw source text regardless of meaning.                          |
| Lexical Duplication            | Similar token sequences after parsing source code.                                   |
| Syntactic Duplication          | Similar AST/code structure despite formatting or naming differences.                 |
| Functional Duplication         | Different implementations achieving the same functional/business outcome.            |
| Architectural Duplication      | Repeated subsystem, service, module, or application architecture patterns.           |
| Process Duplication            | Repeated manual operational or development workflows/processes.                      |
| Cross-Service Duplication      | Duplicate business logic or validation spread across microservices/systems.          |
| Clone Type-1 Duplication       | Exact copied code differing only in whitespace/comments.                             |
| Clone Type-2 Duplication       | Copied code with renamed variables, methods, or types.                               |
| Clone Type-3 Duplication       | Copied code with partial modifications, insertions, or deletions.                    |
| Clone Type-4 Duplication       | Different implementations that perform the same behavior semantically.               |
| Logic Duplication              | Repeated conditional or decision-making logic throughout the system.                 |
| Validation Duplication         | Repeated input validation or business validation rules.                              |
| Error-Handling Duplication     | Repeated exception handling, retry, fallback, or logging patterns.                   |
| State Duplication              | Multiple independent representations of the same mutable state.                      |
| Documentation Duplication      | Repeated or mirrored documentation that can drift out of sync.                       |
| Dependency Duplication         | Multiple versions or repeated inclusion of similar libraries/packages.               |
| Schema Duplication             | Repeated database, API, event, or serialization schema definitions.                  |
| Mapping Duplication            | Repeated object conversion or transformation code between layers/models.             |
| Permission Duplication         | Repeated authorization or access-control rules across components.                    |
| Workflow Duplication           | Repeated orchestration or business-process flow logic.                               |
| Event Duplication              | Multiple systems independently reacting to or recreating the same event semantics.   |
| Caching Duplication            | Duplicate caching logic, invalidation rules, or cache representations.               |
| Localization Duplication       | Repeated translation strings or locale-specific formatting rules.                    |
| Build Duplication              | Repeated CI/CD, build scripts, packaging, or compilation logic.                      |
| Monitoring Duplication         | Repeated metrics, logging, tracing, or observability instrumentation.                |
| Serialization Duplication      | Repeated encoding/decoding logic for JSON, XML, protobuf, etc.                       |
| Integration Duplication        | Repeated glue code integrating with third-party systems/services.                    |
| Policy Duplication             | Repeated business policy or governance rules in multiple locations.                  |
| Domain Rule Duplication        | Core domain/business invariants implemented independently in multiple places.        |
| Security Duplication           | Repeated authentication, encryption, sanitization, or security checks.               |
| Concurrency Duplication        | Repeated synchronization, locking, threading, or async coordination patterns.        |
| Resource Lifecycle Duplication | Repeated acquire/use/release patterns for files, sockets, DB connections, etc.       |
| Boilerplate Duplication        | Repeated scaffolding or framework-required code with minimal variation.              |
| Template Duplication           | Repeated code/templates differing only by injected values or parameters.             |
| Generated-Code Duplication     | Duplicate generated artifacts caused by code generators or schema compilers.         |
| Intentional Duplication        | Deliberate duplication to preserve isolation, simplicity, or deployability.          |
| Accidental Duplication         | Unplanned duplication caused by copy-paste, poor abstraction, or drift.              |

# PHP Duplication Types — Examples and Refactors

This document shows common duplication patterns in PHP codebases. Each section includes:

1. Duplicate implementations
2. Why the duplication is problematic
3. A refactored/deduplicated approach

---

# 1. Data Duplication

## Duplicate Code

```php
function calculateSalesTax(float $amount): float {
    return $amount * 0.07;
}
```

```php
function calculateInvoiceTax(float $amount): float {
    return $amount * 0.07;
}
```

```php
function calculateRefundTax(float $amount): float {
    return $amount * 0.07;
}
```

## Refactored

```php
const TAX_RATE = 0.07;

function calculateTax(float $amount): float {
    return $amount * TAX_RATE;
}
```

---

# 2. Type Duplication

## Duplicate Code

```php
function processUser(User $user): void {
    echo $user->getName();
}
```

```php
function processAdmin(Admin $admin): void {
    echo $admin->getName();
}
```

```php
function processCustomer(Customer $customer): void {
    echo $customer->getName();
}
```

## Refactored

```php
interface NamedEntity {
    public function getName(): string;
}

function processNamedEntity(NamedEntity $entity): void {
    echo $entity->getName();
}
```

---

# 3. Algorithm Duplication

## Duplicate Code

```php
function calculateRegularDiscount(float $price): float {
    return $price * 0.90;
}
```

```php
function calculateHolidayDiscount(float $price): float {
    return $price * 0.85;
}
```

```php
function calculateVipDiscount(float $price): float {
    return $price * 0.80;
}
```

## Refactored

```php
function applyDiscount(float $price, float $discountRate): float {
    return $price * (1 - $discountRate);
}
```

---

# 4. Structural Duplication

## Duplicate Code

```php
function loadUsers(PDO $db): array {
    $stmt = $db->query('SELECT * FROM users');
    return $stmt->fetchAll();
}
```

```php
function loadOrders(PDO $db): array {
    $stmt = $db->query('SELECT * FROM orders');
    return $stmt->fetchAll();
}
```

```php
function loadInvoices(PDO $db): array {
    $stmt = $db->query('SELECT * FROM invoices');
    return $stmt->fetchAll();
}
```

## Refactored

```php
function loadTable(PDO $db, string $table): array {
    $stmt = $db->query("SELECT * FROM {$table}");
    return $stmt->fetchAll();
}
```

---

# 5. Semantic Duplication

## Duplicate Code

```php
function isAdult(User $user): bool {
    return $user->age >= 18;
}
```

```php
function canRegister(User $user): bool {
    return $user->birthDate <= new DateTime('-18 years');
}
```

```php
function allowPurchase(User $user): bool {
    return !$user->isMinor();
}
```

## Refactored

```php
class AgePolicy {
    public static function isAdult(User $user): bool {
        return $user->birthDate <= new DateTime('-18 years');
    }
}
```

---

# 6. Temporal Duplication

## Duplicate Code

```php
$lock->acquire();
updateInventory();
$lock->release();
```

```php
$lock->acquire();
updateOrders();
$lock->release();
```

```php
$lock->acquire();
sendInvoice();
$lock->release();
```

## Refactored

```php
function withLock(Lock $lock, callable $callback): void {
    $lock->acquire();

    try {
        $callback();
    } finally {
        $lock->release();
    }
}
```

---

# 7. Knowledge Duplication

## Duplicate Code

```php
const PASSWORD_MAX_LENGTH = 32;
```

```php
$validator->maxLength('password', 32);
```

```sql
VARCHAR(32)
```

## Refactored

```php
class PasswordRules {
    public const MAX_LENGTH = 32;
}
```

---

# 8. Representation Duplication

## Duplicate Code

```php
class UserDTO {
    public string $name;
    public string $email;
}
```

```php
class UserApiModel {
    public string $name;
    public string $email;
}
```

```php
class UserViewModel {
    public string $name;
    public string $email;
}
```

## Refactored

```php
class UserData {
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

---

# 9. Behavioral Duplication

## Duplicate Code

```php
function isActive(User $user): bool {
    return !$user->disabled && $user->confirmed;
}
```

```php
function canLogin(User $user): bool {
    return $user->confirmed && !$user->disabled;
}
```

```php
function canAccessDashboard(User $user): bool {
    return !$user->disabled && $user->confirmed;
}
```

## Refactored

```php
class UserStatus {
    public static function isActive(User $user): bool {
        return !$user->disabled && $user->confirmed;
    }
}
```

---

# 10. UI Duplication

## Duplicate Code

```php
<input type="text" name="first_name" class="form-control">
```

```php
<input type="text" name="last_name" class="form-control">
```

```php
<input type="text" name="city" class="form-control">
```

## Refactored

```php
function renderInput(string $name): string {
    return sprintf(
        '<input type="text" name="%s" class="form-control">',
        htmlspecialchars($name)
    );
}
```

---

# 11. Configuration Duplication

## Duplicate Code

```yaml
redis_timeout: 30
redis_retries: 3
```

```yaml
cache_timeout: 30
cache_retries: 3
```

```yaml
queue_timeout: 30
queue_retries: 3
```

## Refactored

```yaml
shared_defaults:
  timeout: 30
  retries: 3
```

---

# 12. Test Duplication

## Duplicate Code

```php
$user = UserFactory::create();
$this->actingAs($user);
$response = $this->get('/dashboard');
$response->assertOk();
```

```php
$user = UserFactory::create();
$this->actingAs($user);
$response = $this->get('/settings');
$response->assertOk();
```

## Refactored

```php
function authenticatedGet(string $url): TestResponse {
    $user = UserFactory::create();
    $this->actingAs($user);

    return $this->get($url);
}
```

---

# 13. Query Duplication

## Duplicate Code

```php
$db->query("SELECT * FROM users WHERE deleted = 0");
```

```php
$db->query("SELECT * FROM admins WHERE deleted = 0");
```

```php
$db->query("SELECT * FROM customers WHERE deleted = 0");
```

## Refactored

```php
function selectActive(PDO $db, string $table): array {
    $stmt = $db->query("SELECT * FROM {$table} WHERE deleted = 0");
    return $stmt->fetchAll();
}
```

---

# 14. Protocol Duplication

## Duplicate Code

```php
$response = $client->get($url, [
    'headers' => ['Authorization' => 'Bearer ' . $token],
]);
```

```php
$response = $client->post($url, [
    'headers' => ['Authorization' => 'Bearer ' . $token],
]);
```

## Refactored

```php
class ApiClient {
    public function request(string $method, string $url, array $options = []) {
        $options['headers']['Authorization'] = 'Bearer ' . $this->token;

        return $this->client->request($method, $url, $options);
    }
}
```

---

# 15. Copy-Paste Duplication

## Duplicate Code

```php
$total = $subtotal + $tax;
$formatted = number_format($total, 2);
echo $formatted;
```

```php
$total = $subtotal + $tax;
$formatted = number_format($total, 2);
echo $formatted;
```

## Refactored

```php
function renderTotal(float $subtotal, float $tax): void {
    $total = $subtotal + $tax;
    echo number_format($total, 2);
}
```

---

# 16. Logic Duplication

## Duplicate Code

```php
if ($user->role === 'admin' || $user->role === 'manager') {
    grantAccess();
}
```

```php
if (in_array($user->role, ['admin', 'manager'])) {
    showDashboard();
}
```

## Refactored

```php
function hasElevatedAccess(User $user): bool {
    return in_array($user->role, ['admin', 'manager']);
}
```

---

# 17. Validation Duplication

## Duplicate Code

```php
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException();
}
```

```php
if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException();
}
```

## Refactored

```php
function validateEmail(string $email): void {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email');
    }
}
```

---

# 18. Error-Handling Duplication

## Duplicate Code

```php
try {
    syncUsers();
} catch (Throwable $e) {
    logger()->error($e->getMessage());
}
```

```php
try {
    syncOrders();
} catch (Throwable $e) {
    logger()->error($e->getMessage());
}
```

## Refactored

```php
function safely(callable $callback): void {
    try {
        $callback();
    } catch (Throwable $e) {
        logger()->error($e->getMessage());
    }
}
```

---

# 19. State Duplication

## Duplicate Code

```php
$user->balance = 100;
$cache['balance'] = 100;
$_SESSION['balance'] = 100;
```

## Refactored

```php
class BalanceService {
    public function set(User $user, float $balance): void {
        $user->balance = $balance;
    }
}
```

---

# 20. Documentation Duplication

## Duplicate Code

```php
// Max upload size: 20MB
```

```markdown
Maximum upload size is 20MB.
```

```yaml
upload_limit: 20MB
```

## Refactored

```php
class UploadConfig {
    public const MAX_SIZE_MB = 20;
}
```

---

# 21. Dependency Duplication

## Duplicate Code

```json
"monolog/monolog": "^2.0"
```

```json
"monolog/monolog": "^2.1"
```

```json
"monolog/monolog": "^3.0"
```

## Refactored

```json
"monolog/monolog": "^3.0"
```

---

# 22. Schema Duplication

## Duplicate Code

```php
'name' => 'required|string|max:255'
```

```php
#[Assert\Length(max: 255)]
```

```sql
VARCHAR(255)
```

## Refactored

```php
class UserSchema {
    public const NAME_MAX_LENGTH = 255;
}
```

---

# 23. Mapping Duplication

## Duplicate Code

```php
$userDto->name = $user->name;
$userDto->email = $user->email;
```

```php
$payload['name'] = $user->name;
$payload['email'] = $user->email;
```

## Refactored

```php
function mapUser(User $user): array {
    return [
        'name' => $user->name,
        'email' => $user->email,
    ];
}
```

---

# 24. Permission Duplication

## Duplicate Code

```php
if (!$user->isAdmin()) {
    abort(403);
}
```

```php
if (!$user->isAdmin()) {
    throw new AuthorizationException();
}
```

## Refactored

```php
function requireAdmin(User $user): void {
    if (!$user->isAdmin()) {
        throw new AuthorizationException();
    }
}
```

---

# 25. Workflow Duplication

## Duplicate Code

```php
validateOrder();
chargeCard();
sendReceipt();
```

```php
validateSubscription();
chargeCard();
sendReceipt();
```

## Refactored

```php
function executePurchaseWorkflow(callable $validator): void {
    $validator();
    chargeCard();
    sendReceipt();
}
```

---

# 26. Security Duplication

## Duplicate Code

```php
$password = password_hash($password, PASSWORD_BCRYPT);
```

```php
$pin = password_hash($pin, PASSWORD_BCRYPT);
```

## Refactored

```php
function hashSecret(string $secret): string {
    return password_hash($secret, PASSWORD_BCRYPT);
}
```

---

# 27. Resource Lifecycle Duplication

## Duplicate Code

```php
$file = fopen($path, 'r');
$data = fread($file, filesize($path));
fclose($file);
```

```php
$file = fopen($otherPath, 'r');
$data = fread($file, filesize($otherPath));
fclose($file);
```

## Refactored

```php
function readFileContents(string $path): string {
    $file = fopen($path, 'r');

    try {
        return fread($file, filesize($path));
    } finally {
        fclose($file);
    }
}
```

---

# 28. Boilerplate Duplication

## Duplicate Code

```php
class UserController {
    public function __construct(private Logger $logger) {}
}
```

```php
class OrderController {
    public function __construct(private Logger $logger) {}
}
```

## Refactored

```php
abstract class BaseController {
    public function __construct(protected Logger $logger) {}
}
```

---

# 29. Template Duplication

## Duplicate Code

```php
<h1>User Report</h1>
<p>Total: <?= $total ?></p>
```

```php
<h1>Sales Report</h1>
<p>Total: <?= $total ?></p>
```

## Refactored

```php
function renderReport(string $title, float $total): void {
    echo "<h1>{$title}</h1>";
    echo "<p>Total: {$total}</p>";
}
```

---

# 30. Concurrency Duplication

## Duplicate Code

```php
$mutex->lock();
updateStats();
$mutex->unlock();
```

```php
$mutex->lock();
updateCache();
$mutex->unlock();
```

## Refactored

```php
function synchronized(Mutex $mutex, callable $callback): void {
    $mutex->lock();

    try {
        $callback();
    } finally {
        $mutex->unlock();
    }
}
```

---

# 31. Monitoring Duplication

## Duplicate Code

```php
$metrics->increment('users.created');
```

```php
$metrics->increment('orders.created');
```

```php
$metrics->increment('tickets.created');
```

## Refactored

```php
function trackCreated(string $entity): void {
    metrics()->increment("{$entity}.created");
}
```

---

# 32. Serialization Duplication

## Duplicate Code

```php
json_encode([
    'id' => $user->id,
    'name' => $user->name,
]);
```

```php
json_encode([
    'id' => $admin->id,
    'name' => $admin->name,
]);
```

## Refactored

```php
function serializeIdentity(object $entity): string {
    return json_encode([
        'id' => $entity->id,
        'name' => $entity->name,
    ]);
}
```

---

# 33. Integration Duplication

## Duplicate Code

```php
$mailchimp->subscribe($email);
logger()->info('Subscribed');
```

```php
$sendgrid->subscribe($email);
logger()->info('Subscribed');
```

## Refactored

```php
function subscribe(NewsletterProvider $provider, string $email): void {
    $provider->subscribe($email);
    logger()->info('Subscribed');
}
```

---

# 34. Policy Duplication

## Duplicate Code

```php
if ($invoice->amount > 10000) {
    requireManagerApproval();
}
```

```php
if ($purchase->amount > 10000) {
    requireManagerApproval();
}
```

## Refactored

```php
class ApprovalPolicy {
    public static function requiresManagerApproval(float $amount): bool {
        return $amount > 10000;
    }
}
```

---

# 35. Domain Rule Duplication

## Duplicate Code

```php
if ($account->balance < 0) {
    throw new RuntimeException('Overdraft not allowed');
}
```

```php
if ($wallet->balance < 0) {
    throw new RuntimeException('Overdraft not allowed');
}
```

## Refactored

```php
class BalanceRules {
    public static function ensureNonNegative(float $balance): void {
        if ($balance < 0) {
            throw new RuntimeException('Overdraft not allowed');
        }
    }
}
```

