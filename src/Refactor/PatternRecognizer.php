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
