<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Phpdup\Clustering\Cluster;

/**
 * Tags clusters whose shape matches well-known refactor archetypes.
 *
 * Tags are advisory — they don't change clustering, just add a one-line
 * label in the report so a human can recognize "ah, this is a strategy
 * pattern" without staring at the AST.  They do feed
 * SafetyScorer::TAG_DELTAS and surface as user-visible pattern claims
 * (JSON `pattern_tags`, CLI/HTML chips), so every tag must be backed by
 * evidence, never by the mere absence of an AST.
 *
 * AST policy (output-021 fail-closed law): detectors fall into two
 * families.  *Name-based* detectors read only metadata that survives
 * lazy parsing — class name, method name, file path, namespace, hole
 * kinds — and work whether or not member ASTs are loaded.  *AST-based*
 * detectors inspect statement shape and refuse to tag members whose
 * AST was never materialised (`$m->ast === null` → no evidence → no
 * tag, exactly like the `continue`-on-null guard used elsewhere).
 *
 * Full tag inventory, matching what tag() emits:
 *
 *   --- hole-shape based (no AST needed) ---
 *   config-driven         — only literal holes; a pure values-vary case
 *   strategy              — single 'name' or 'call' hole; rest of body
 *                           identical
 *   optional-segments     — at least one 'optional_block' hole
 *
 *   --- name-based (no AST needed) ---
 *   crud-handler          — a whole name segment (camel/snake split)
 *                           equals a CRUD verb: create/read/update/
 *                           delete, insert/select/remove/fetch/find
 *   controller-action     — class ends in `Controller` or file lives
 *                           under Http/Controllers/ or Controller/
 *   migration             — file under migrations/ and member `up`/`down`
 *   eloquent-model        — namespace under \Models\, class not a
 *                           controller/repository
 *   repository-method     — class ends in `Repository`, member name
 *                           starts with a repository verb
 *   event-listener        — class ends in Listener/Subscriber/EventHandler
 *   service-provider      — class ends in ServiceProvider/Bundle, member
 *                           register/boot/build/configure
 *
 *   --- AST-based (require loaded member ASTs) ---
 *   validation-chain      — sequence of if-then-throw or if-then-return
 *                           followed by a success path
 *   sql-builder           — string concatenation feeding a
 *                           query|prepare|exec|fetch call, or a
 *                           literal holding a real SQL statement
 *                           (uppercase verb + FROM/INTO <table> — not
 *                           English prose that merely contains "from")
 *   state-machine         — switch/match over a state whose literal arms
 *                           share one body shape, varying only by the
 *                           state discriminator (≥2 literal arms)
 *   query-builder-chain   — body calls createQueryBuilder/getQueryBuilder
 *                           or a static table()/query() entrypoint
 *   loop-map              — foreach whose body appends to an accumulator
 *                           array (array_map-shaped)
 *   loop-filter           — foreach starting with `if (...) continue`
 *   sql-query             — a string literal starting with a SQL verb
 *   http-call             — an HTTP-shaped call (verb MethodCall with
 *                           args, or curl_/wp_remote_/file_get_contents)
 *                           whose own arguments carry the URL
 *   error-handler         — try/catch whose catch body logs, rethrows,
 *                           or returns
 *   builder-chain         — ≥3 chained method calls on one head
 *   container-registration — a register/boot/configure-named method
 *                           whose body actually calls bind/set/register/
 *                           singleton/instance/extend/autowire
 *   db-op                 — __DB_*__ synthetic calls or nodes carrying
 *                           the phpdup.dbOp attribute
 */
final class PatternRecognizer
{
    public function tag(Cluster $cluster): void
    {
        $tags = [];
        if ($this->isConfigDriven($cluster))    $tags[] = 'config-driven';
        if ($this->isStrategy($cluster))        $tags[] = 'strategy';
        if ($this->isCrudHandler($cluster))     $tags[] = 'crud-handler';
        if ($this->isValidationChain($cluster)) $tags[] = 'validation-chain';
        if ($this->isSqlBuilder($cluster))      $tags[] = 'sql-builder';
        if ($this->isStateMachine($cluster))    $tags[] = 'state-machine';
        if ($this->hasOptionalSegments($cluster)) $tags[] = 'optional-segments';
        // Framework-aware tags (IX.A). Inferred from qualified-name
        // patterns + member naming, so the name-based ones work even
        // when the cluster members have unloaded ASTs.
        if ($this->isControllerAction($cluster))     $tags[] = 'controller-action';
        if ($this->isMigration($cluster))            $tags[] = 'migration';
        if ($this->isEloquentModel($cluster))        $tags[] = 'eloquent-model';
        if ($this->isRepositoryMethod($cluster))     $tags[] = 'repository-method';
        if ($this->isEventListener($cluster))        $tags[] = 'event-listener';
        if ($this->isServiceProvider($cluster))      $tags[] = 'service-provider';
        if ($this->isQueryBuilderChain($cluster))    $tags[] = 'query-builder-chain';
        // I.A.2-7: domain-pattern tags (loop / SQL / HTTP / error / builder / DI).
        if ($this->isLoopMap($cluster))              $tags[] = 'loop-map';
        if ($this->isLoopFilter($cluster))           $tags[] = 'loop-filter';
        if ($this->isSqlQuery($cluster))             $tags[] = 'sql-query';
        if ($this->isHttpCall($cluster))             $tags[] = 'http-call';
        if ($this->isErrorHandler($cluster))         $tags[] = 'error-handler';
        if ($this->isBuilderChain($cluster))         $tags[] = 'builder-chain';
        if ($this->isContainerRegistration($cluster)) $tags[] = 'container-registration';
        if ($this->isDbOp($cluster))                 $tags[] = 'db-op';
        $cluster->patternTags = $tags;
    }

    private function hasOptionalSegments(Cluster $cluster): bool
    {
        foreach ($cluster->holes as $h) {
            if ($h->kind === 'optional_block') return true;
        }
        return false;
    }

    private function isConfigDriven(Cluster $cluster): bool
    {
        if (!$cluster->holes) return false;
        foreach ($cluster->holes as $h) {
            if ($h->kind !== 'literal') return false;
        }
        return true;
    }

    private function isStrategy(Cluster $cluster): bool
    {
        if (count($cluster->holes) !== 1) return false;
        return in_array($cluster->holes[0]->kind, ['name', 'call'], true);
    }

    private function isCrudHandler(Cluster $cluster): bool
    {
        $verbs = ['create', 'read', 'update', 'delete', 'insert', 'select', 'remove', 'fetch', 'find'];
        foreach ($cluster->members as $m) {
            // Whole-segment match only: a camel/snake name segment must BE
            // the verb. Bare substring matching tagged thread() as "read",
            // preselect() as "select" and prefetch() as "fetch" (output-021).
            foreach (self::nameSegments((string)$m->name) as $segment) {
                if (in_array($segment, $verbs, true)) return true;
            }
        }
        return false;
    }

    /**
     * Split a member name into lower-cased identifier segments at
     * camelCase humps and underscores, so word boundary checks become
     * exact segment equality ("findById" → find, by, id).
     *
     * @return list<string>
     */
    private static function nameSegments(string $name): array
    {
        $parts = preg_split('/(?<=[a-z0-9])(?=[A-Z])|_/', $name) ?: [];
        $out   = [];
        foreach ($parts as $p) {
            $p = strtolower($p);
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }

    private function isValidationChain(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $ifs = $finder->findInstanceOf([$m->ast], Node\Stmt\If_::class);
            if (count($ifs) < 2) continue;
            $allShortCircuit = true;
            foreach ($ifs as $if) {
                /** @var Node\Stmt\If_ $if */
                $stmts = $if->stmts;
                $last = end($stmts);
                if (!$last) { $allShortCircuit = false; break; }
                // PHP-Parser 5 only has Node\Expr\Throw_ wrapped in Stmt\Expression — Stmt\Throw_ no longer exists.
                if (!($last instanceof Node\Stmt\Return_)
                    && !($last instanceof Node\Stmt\Expression && $last->expr instanceof Node\Expr\Throw_)
                ) {
                    $allShortCircuit = false; break;
                }
            }
            if ($allShortCircuit) return true;
        }
        return false;
    }

    private function isSqlBuilder(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $concats = $finder->findInstanceOf([$m->ast], Node\Expr\BinaryOp\Concat::class);
            $calls = $finder->find([$m->ast], static function (Node $n) {
                if ($n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier) {
                    return (bool)preg_match('/(query|prepare|exec|fetch)/i', $n->name->name);
                }
                if ($n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name) {
                    return (bool)preg_match('/(query|prepare|exec|fetch)/i', $n->name->toString());
                }
                return false;
            });
            if ($concats && $calls) return true;

            // Second arm: a literal holding an actual SQL statement —
            // an uppercase verb plus a FROM/INTO <table> token. A bare
            // keyword anywhere in a string is English prose, not SQL
            // ("loaded from cache" used to qualify; output-021).
            $strings = $finder->findInstanceOf([$m->ast], Node\Scalar\String_::class);
            foreach ($strings as $s) {
                /** @var Node\Scalar\String_ $s */
                if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/', $s->value)
                    && preg_match('/\b(FROM|INTO)\s+[A-Za-z_]\w*/', $s->value)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * State-machine: a switch/match whose arms are state literals and
     * whose bodies share one shape, varying only by the state
     * discriminator. Presence of any switch alone is NOT evidence —
     * tagging it lied about unconditionally-shaped members (output-021).
     *
     * Requirements per switch/match:
     *  - at least two arms whose condition is a state literal
     *    (string/int/float scalar or a constant fetch);
     *  - every non-default arm body has the same structural signature
     *    once literal values are abstracted (default/else arms may
     *    differ — a catch-all is not a state).
     */
    private function isStateMachine(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;

            $switches = $finder->findInstanceOf([$m->ast], Node\Stmt\Switch_::class);
            foreach ($switches as $sw) {
                /** @var Node\Stmt\Switch_ $sw */
                $arms = [];
                foreach ($sw->cases as $case) {
                    if ($case->cond === null) continue; // default arm: not a state
                    if (!self::isStateLiteral($case->cond)) { $arms = []; break; }
                    $arms[] = self::signatureOf($case->stmts);
                }
                if (count($arms) >= 2 && count(array_unique($arms)) === 1) return true;
            }

            $matches = $finder->findInstanceOf([$m->ast], Node\Expr\Match_::class);
            foreach ($matches as $mt) {
                /** @var Node\Expr\Match_ $mt */
                $arms = [];
                foreach ($mt->arms as $arm) {
                    // v5 MatchArm: default arm has conds === null; empty array
                    // also means "no state discriminator" — neither is a state.
                    if (!$arm->conds) continue;
                    $literals = true;
                    foreach ($arm->conds as $cond) {
                        if (!self::isStateLiteral($cond)) { $literals = false; break; }
                    }
                    if (!$literals) { $arms = []; break; }
                    $arms[] = self::signatureOf($arm->body);
                }
                if (count($arms) >= 2 && count(array_unique($arms)) === 1) return true;
            }
        }
        return false;
    }

    /** A switch/match arm condition that identifies a state value. */
    private static function isStateLiteral(Node $n): bool
    {
        return $n instanceof Node\Scalar
            || $n instanceof Node\Expr\ConstFetch
            || ($n instanceof Node\Expr\ClassConstFetch && $n->name instanceof Node\Identifier);
    }

    /**
     * Structural signature of a node subtree: node classes, child names
     * and structure — literal STATE values (scalars, constant fetches)
     * collapse to a placeholder so arms that differ only by their
     * discriminator hash equal.
     *
     * @param Node|Node[] $nodes
     */
    private static function signatureOf(Node|array $nodes): string
    {
        if ($nodes instanceof Node) return self::signatureOfNode($nodes);
        $out = '';
        foreach ($nodes as $n) $out .= self::signatureOfNode($n);
        return $out;
    }

    private static function signatureOfNode(Node $n): string
    {
        if ($n instanceof Node\Scalar)  return 'LIT';
        if ($n instanceof Node\Expr\ConstFetch
            || ($n instanceof Node\Expr\ClassConstFetch && $n->name instanceof Node\Identifier)) {
            return 'LIT';
        }
        $parts = [get_class($n)];
        foreach ($n->getSubNodeNames() as $name) {
            $value = $n->{$name};
            $parts[] = $name . '=' . self::signatureOfValue($value);
        }
        return '(' . implode(' ', $parts) . ')';
    }

    private static function signatureOfValue(mixed $v): string
    {
        if ($v instanceof Node)             return self::signatureOfNode($v);
        if (is_array($v)) {
            $out = '';
            foreach ($v as $item) $out .= self::signatureOfValue($item);
            return '[' . $out . ']';
        }
        if ($v === null)  return 'null';
        if (is_bool($v))  return $v ? '1' : '0';
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string)$v;
        return 'x';
    }

    /**
     * Controller-action: class name ends in `Controller` and at least
     * one member name matches a typical action verb (index/show/store/
     * update/destroy/edit/create) OR the file path contains
     * `Http/Controllers/` (Laravel) / `Controller/` (Symfony).
     */
    private function isControllerAction(Cluster $cluster): bool
    {
        foreach ($cluster->members as $m) {
            $cls = (string)$m->class;
            if (str_ends_with($cls, 'Controller') || str_contains($cls, 'Controller\\')) {
                return true;
            }
            $f = $m->file;
            if (str_contains($f, '/Http/Controllers/') || str_contains($f, '/Controller/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Migration: file path contains `database/migrations/` (Laravel) or
     * `Migrations/` (Symfony Doctrine), and the cluster member is named
     * `up` or `down`.
     */
    private function isMigration(Cluster $cluster): bool
    {
        foreach ($cluster->members as $m) {
            $f = $m->file;
            $mIsMigrationFile = str_contains($f, '/migrations/')
                || str_contains($f, '/Migrations/');
            if (!$mIsMigrationFile) continue;
            $name = (string)$m->name;
            if ($name === 'up' || $name === 'down') return true;
        }
        return false;
    }

    /**
     * Eloquent model: namespace ends in \\Models\\ (Laravel convention)
     * AND the class name doesn't look like a controller/repository.
     */
    private function isEloquentModel(Cluster $cluster): bool
    {
        foreach ($cluster->members as $m) {
            $ns = (string)$m->namespace;
            if (str_ends_with($ns, '\\Models') || str_contains($ns, '\\Models\\')) {
                $cls = (string)$m->class;
                if (!str_ends_with($cls, 'Controller') && !str_ends_with($cls, 'Repository')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Repository method: class name ends in `Repository` and the member
     * name is a typical repository verb (find/get/save/delete/count).
     */
    private function isRepositoryMethod(Cluster $cluster): bool
    {
        $verbs = ['find', 'get', 'save', 'delete', 'remove', 'count', 'fetch'];
        foreach ($cluster->members as $m) {
            $cls = (string)$m->class;
            if (!str_ends_with($cls, 'Repository')) continue;
            $name = strtolower((string)$m->name);
            foreach ($verbs as $v) {
                if (str_starts_with($name, $v)) return true;
            }
        }
        return false;
    }

    /**
     * Event listener / subscriber: class ends in `Listener`,
     * `Subscriber`, or `EventHandler`. Member name typically `handle`.
     */
    private function isEventListener(Cluster $cluster): bool
    {
        foreach ($cluster->members as $m) {
            $cls = (string)$m->class;
            if (str_ends_with($cls, 'Listener')
                || str_ends_with($cls, 'Subscriber')
                || str_ends_with($cls, 'EventHandler')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Service provider: class name ends in `ServiceProvider` (Laravel)
     * or `Bundle` (Symfony). Member typically `register` / `boot` /
     * `build`.
     */
    private function isServiceProvider(Cluster $cluster): bool
    {
        foreach ($cluster->members as $m) {
            $cls = (string)$m->class;
            if (!str_ends_with($cls, 'ServiceProvider')
                && !str_ends_with($cls, 'Bundle')
            ) {
                continue;
            }
            $name = (string)$m->name;
            if (in_array($name, ['register', 'boot', 'build', 'configure'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Loop-map: foreach (...) { $acc[] = ...; } — accumulator-style
     * loop body that's expressible as `array_map`.
     */
    private function isLoopMap(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $foreaches = $finder->findInstanceOf([$m->ast], Node\Stmt\Foreach_::class);
            foreach ($foreaches as $foreach) {
                if (!$foreach instanceof Node\Stmt\Foreach_) continue;
                foreach ($foreach->stmts as $s) {
                    if ($s instanceof Node\Stmt\Expression
                        && $s->expr instanceof Node\Expr\Assign
                        && $s->expr->var instanceof Node\Expr\ArrayDimFetch
                    ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Loop-filter: foreach (...) { if (!cond) continue; ... }
     */
    private function isLoopFilter(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $foreaches = $finder->findInstanceOf([$m->ast], Node\Stmt\Foreach_::class);
            foreach ($foreaches as $f) {
                /** @var Node\Stmt\Foreach_ $f */
                $first = $f->stmts[0] ?? null;
                if ($first instanceof Node\Stmt\If_) {
                    foreach ($first->stmts as $s) {
                        if ($s instanceof Node\Stmt\Continue_) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * SQL query: any string literal in the body that looks like SQL.
     * (Stronger than isSqlBuilder; doesn't require concat or prepare.)
     */
    private function isSqlQuery(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $strings = $finder->findInstanceOf([$m->ast], Node\Scalar\String_::class);
            foreach ($strings as $s) {
                /** @var Node\Scalar\String_ $s */
                if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $s->value)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * HTTP call: a verb-shaped MethodCall with args (get/post/put/patch/
     * delete/request/send) or a curl_ / wp_remote_ / file_get_contents
     * function call — AND the URL must be an argument of that same call.
     * A URL string floating anywhere in the body (config defaults
     * carrying example URLs) is not evidence of an HTTP call
     * (output-021).
     */
    private function isHttpCall(Cluster $cluster): bool
    {
        static $verbs = ['get', 'post', 'put', 'patch', 'delete', 'request', 'send'];
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $hits = $finder->find([$m->ast], static function (Node $n) use ($verbs): bool {
                if ($n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier) {
                    return in_array(strtolower($n->name->name), $verbs, true)
                        && $n->args !== []  // pure verb names with args look like HTTP
                        ;
                }
                if ($n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name) {
                    $name = strtolower($n->name->toString());
                    return str_starts_with($name, 'curl_')
                        || str_starts_with($name, 'wp_remote_')
                        || $name === 'file_get_contents';
                }
                return false;
            });
            foreach ($hits as $hit) {
                if (!$hit instanceof Node\Expr\MethodCall && !$hit instanceof Node\Expr\FuncCall) {
                    continue;
                }
                foreach ($hit->args as $arg) {
                    if ($arg instanceof Node\Arg && self::looksLikeUrl($arg->value)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Does this expression carry an http(s) URL? Direct string literals
     * and concatenations whose literal part opens the URL count; a bare
     * variable does not (we cannot know its value).
     */
    private static function looksLikeUrl(Node\Expr $e): bool
    {
        if ($e instanceof Node\Scalar\String_) {
            return (bool)preg_match('#^https?://#i', $e->value);
        }
        if ($e instanceof Node\Scalar\InterpolatedString) {
            foreach ($e->parts as $part) {
                if ($part instanceof Node\InterpolatedStringPart
                    && preg_match('#^https?://#i', $part->value)) {
                    return true;
                }
            }
            return false;
        }
        if ($e instanceof Node\Expr\BinaryOp\Concat) {
            return self::looksLikeUrl($e->left);
        }
        return false;
    }

    /**
     * Error-handler: try { ... } catch (...) { ... } where the catch
     * body is a logger call, rethrow, or single return.
     */
    private function isErrorHandler(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $tries = $finder->findInstanceOf([$m->ast], Node\Stmt\TryCatch::class);
            foreach ($tries as $tryStmt) {
                if (!$tryStmt instanceof Node\Stmt\TryCatch) continue;
                foreach ($tryStmt->catches as $catch) {
                    foreach ($catch->stmts as $s) {
                        if ($s instanceof Node\Stmt\Return_)  return true;
                        if ($s instanceof Node\Stmt\Expression
                            && ($s->expr instanceof Node\Expr\Throw_
                                || ($s->expr instanceof Node\Expr\MethodCall
                                    && $s->expr->name instanceof Node\Identifier
                                    && preg_match('/^(log|error|warn|info|debug)$/i', $s->expr->name->name))
                            )
                        ) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * Builder-chain: $x->...->...->...  with ≥3 method calls in a row
     * on the same chain head.
     */
    private function isBuilderChain(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $methodCalls = $finder->findInstanceOf([$m->ast], Node\Expr\MethodCall::class);
            foreach ($methodCalls as $mc) {
                $depth = 0;
                $cursor = $mc;
                while ($cursor instanceof Node\Expr\MethodCall) {
                    $depth++;
                    $cursor = $cursor->var;
                }
                if ($depth >= 3) return true;
            }
        }
        return false;
    }

    /**
     * Container registration: cluster lives inside a method named
     * register* / boot* / configureServices* and its (loaded) body
     * actually calls `->bind(...)` / `->set(...)` / `->register(...)`
     * etc.  An unloaded AST gives no evidence — fail closed like the
     * other AST-based detectors (output-021: it used to auto-yes).
     */
    private function isContainerRegistration(Cluster $cluster): bool
    {
        static $verbs = ['bind', 'set', 'register', 'singleton', 'instance', 'extend', 'autowire'];
        foreach ($cluster->members as $m) {
            $name = strtolower((string)$m->name);
            if (!preg_match('/^(register|boot|configure)/', $name)) {
                continue;
            }
            if ($m->ast === null) continue; // no evidence, no tag
            $finder = new NodeFinder();
            $calls  = $finder->findInstanceOf([$m->ast], Node\Expr\MethodCall::class);
            foreach ($calls as $mc) {
                if ($mc->name instanceof Node\Identifier
                    && in_array(strtolower($mc->name->name), $verbs, true)
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Query-builder chain: the body contains a method-call chain whose
     * head is recognised as a Doctrine / Eloquent / DBAL builder
     * entrypoint (`createQueryBuilder`, `DB::table`, `Model::query`).
     */
    private function isQueryBuilderChain(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $hits = $finder->find([$m->ast], static function (Node $n) {
                if ($n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier
                    && in_array($n->name->name, ['createQueryBuilder', 'getQueryBuilder'], true)
                ) {
                    return true;
                }
                if ($n instanceof Node\Expr\StaticCall && $n->name instanceof Node\Identifier
                    && in_array($n->name->name, ['table', 'query'], true)
                ) {
                    return true;
                }
                return false;
            });
            if (!empty($hits)) return true;
        }
        return false;
    }

    /**
     * Database-operation tag: cluster contains __DB_READ__, __DB_WRITE__,
     * __DB_UPSERT__ or similar synthetic function calls produced by the
     * DbOpCanonicalizer during --db-aware normalization.
     */
    private function isDbOp(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            if ($m->ast === null) continue;
            $funcCalls = $finder->findInstanceOf([$m->ast], Node\Expr\FuncCall::class);
            foreach ($funcCalls as $call) {
                if (!$call instanceof Node\Expr\FuncCall) continue;
                if ($call->name instanceof Node\Name) {
                    $name = $call->name->toString();
                    if (str_starts_with($name, '__DB_') && str_ends_with($name, '__')) {
                        return true;
                    }
                }
            }
            // Also check for phpdup.dbOp attribute on any node
            $hits = $finder->find([$m->ast], static function (Node $n): bool {
                return $n->hasAttribute('phpdup.dbOp');
            });
            if (!empty($hits)) return true;
        }
        return false;
    }
}
