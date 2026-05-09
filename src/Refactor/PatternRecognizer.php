<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Phpdup\Clustering\Cluster;
use Phpdup\Refactor\Hole;

/**
 * Tags clusters whose shape matches well-known refactor archetypes.
 *
 * Tags are advisory — they don't change clustering, just add a one-line
 * label in the report so a human can recognize "ah, this is a strategy
 * pattern" without staring at the AST.
 *
 * Implemented patterns (best-effort heuristics):
 *
 *   sql-builder         — body contains string concatenation feeding a
 *                         method/function call whose name matches
 *                         /query|prepare|exec|fetch/i
 *   validation-chain    — sequence of if-then-throw or if-then-return
 *                         followed by a success path
 *   crud-handler        — name contains create/read/update/delete OR
 *                         insert/select/update/delete
 *   strategy            — single 'name' or 'call' hole; rest of body
 *                         identical
 *   config-driven       — only literal holes; a pure values-vary case
 *   state-machine       — switch/match where each arm has the same shape
 *                         varying only by a state literal
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
        // patterns + member naming so they work even when the cluster
        // members have unloaded ASTs.
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
            $name = strtolower((string)$m->name);
            foreach ($verbs as $v) {
                if (str_contains($name, $v)) return true;
            }
        }
        return false;
    }

    private function isValidationChain(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
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

            // also: any string contains SQL keywords
            $strings = $finder->findInstanceOf([$m->ast], Node\Scalar\String_::class);
            foreach ($strings as $s) {
                /** @var Node\Scalar\String_ $s */
                if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE)\b/i', $s->value)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isStateMachine(Cluster $cluster): bool
    {
        $finder = new NodeFinder();
        foreach ($cluster->members as $m) {
            $switches = $finder->findInstanceOf([$m->ast], Node\Stmt\Switch_::class);
            if ($switches) return true;
            $matches = $finder->findInstanceOf([$m->ast], Node\Expr\Match_::class);
            if ($matches) return true;
        }
        return false;
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
     * Query-builder chain: the body contains a method-call chain whose
     * head is recognised as a Doctrine / Eloquent / DBAL builder
     * entrypoint (`createQueryBuilder`, `DB::table`, `Model::query`).
     */
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
     * HTTP call: a method call whose name matches GET/POST/PUT/PATCH/
     * DELETE on a Guzzle / HttpClient-shaped object, or a function
     * call whose name contains 'curl_', 'http_', 'wp_remote_'.
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
            // Methods named get/post are common; require an HTTP-shaped callee
            // string to avoid false positives. As a quick heuristic, also look
            // for a Url/Uri argument or variable name in the same block.
            if (!empty($hits)) {
                $hasUrl = $finder->find([$m->ast], static function (Node $n): bool {
                    if ($n instanceof Node\Scalar\String_) {
                        return (bool)preg_match('#^https?://#i', $n->value);
                    }
                    return false;
                });
                if (!empty($hasUrl)) return true;
            }
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
     * register* / boot* / configureServices* and consists of `->bind(...)`
     * / `->set(...)` / `->register(...)` calls.
     */
    private function isContainerRegistration(Cluster $cluster): bool
    {
        static $verbs = ['bind', 'set', 'register', 'singleton', 'instance', 'extend', 'autowire'];
        foreach ($cluster->members as $m) {
            $name = strtolower((string)$m->name);
            if (!preg_match('/^(register|boot|configure)/', $name)) {
                continue;
            }
            if ($m->ast === null) return true; // strong signal from method name alone
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
}
