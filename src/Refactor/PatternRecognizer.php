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
        $cluster->patternTags = $tags;
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
                if (!($last instanceof Node\Stmt\Throw_)
                    && !($last instanceof Node\Stmt\Return_)
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
}
