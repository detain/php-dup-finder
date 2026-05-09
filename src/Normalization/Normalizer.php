<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpdup\Extraction\Block;

/**
 * Rewrites a Block's AST into a canonical form for hashing/clustering.
 *
 * Three orthogonal canonicalizations, each toggled by mode:
 *
 *   1. Identifier canonicalization. Local variables and parameters are
 *      renamed to $__V0, $__V1, … in first-occurrence order. Two
 *      structurally identical functions whose only difference is local
 *      variable names produce identical canonical ASTs.
 *
 *   2. Literal canonicalization. Scalar literals (string/int/float) are
 *      replaced by typed sentinel values: "__STR", 0, 0.0. The original
 *      values are preserved on the *original* AST so anti-unification
 *      can recover them.
 *
 *   3. Name canonicalization (aggressive only). Function names, method
 *      names, property names, class-string keys, and class names in
 *      `new X(…)`/`X::foo(…)` are replaced by sentinel names like
 *      __CALL, __PROP, __KEY, __CLASS. This is the canonicalization
 *      that lets two SQL-builders or two CRUD handlers cluster.
 *
 * Modes:
 *   strict      — only #1
 *   default     — #1 + #2
 *   aggressive  — #1 + #2 + #3   (default for the tool)
 *
 * The original AST is not mutated; the canonical AST lives at
 * Block::canonical and is a deep clone.
 */
final class Normalizer
{
    public function __construct(private readonly string $mode = 'aggressive')
    {
    }

    public function normalize(Block $block): void
    {
        $clone = self::deepClone($block->ast);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CanonicalizingVisitor($this->mode));
        $stmts = $traverser->traverse([$clone]);
        $block->canonical = $stmts[0];
    }

    /** Deep-clone via serialization — PhpParser nodes are plain PHP objects. */
    public static function deepClone(Node $node): Node
    {
        return unserialize(serialize($node));
    }
}

/** @internal */
final class CanonicalizingVisitor extends NodeVisitorAbstract
{
    /** @var array<string,string> */
    private array $varRenames = [];
    private int $varCounter = 0;

    /** @var array<string,string> */
    private array $callRenames = [];
    private int $callCounter = 0;

    /** @var array<string,string> */
    private array $propRenames = [];
    private int $propCounter = 0;

    /** @var array<string,string> */
    private array $classRenames = [];
    private int $classCounter = 0;

    public function __construct(private readonly string $mode)
    {
    }

    public function enterNode(Node $node): null
    {
        $this->canonicalizeVariables($node);
        if ($this->mode === 'strict') {
            return null;
        }
        $this->canonicalizeLiterals($node);
        if ($this->mode !== 'aggressive') {
            return null;
        }
        $this->canonicalizeNames($node);
        return null;
    }

    private function canonicalizeVariables(Node $node): void
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            if ($node->name === 'this') {
                return; // keep $this — semantically distinct
            }
            $node->name = $this->getOrAssign($this->varRenames, $node->name, '__V', $this->varCounter);
            return;
        }
        if ($node instanceof Node\Param && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            if ($node->var->name !== 'this') {
                $node->var->name = $this->getOrAssign($this->varRenames, $node->var->name, '__V', $this->varCounter);
            }
            return;
        }
        // Function and method declaration names are container labels, not
        // body content — collapse so two equally-shaped functions with
        // different names produce identical canonical hashes.
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $node->name = new Node\Identifier('__FN');
        }
    }

    private function canonicalizeLiterals(Node $node): void
    {
        if ($node instanceof Node\Scalar\String_) {
            $node->value = '__STR';
            return;
        }
        if ($node instanceof Node\Scalar\Int_) {
            $node->value = 0;
            return;
        }
        if ($node instanceof Node\Scalar\Float_) {
            $node->value = 0.0;
            return;
        }
        if ($node instanceof Node\Scalar\InterpolatedString) {
            foreach ($node->parts as $p) {
                if ($p instanceof Node\InterpolatedStringPart) {
                    $p->value = '__STR';
                }
            }
        }
    }

    private function canonicalizeNames(Node $node): void
    {
        // method/function call names
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier($this->getOrAssign($this->callRenames, $node->name->name, '__CALL', $this->callCounter));
            return;
        }
        if ($node instanceof Node\Expr\NullsafeMethodCall && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier($this->getOrAssign($this->callRenames, $node->name->name, '__CALL', $this->callCounter));
            return;
        }
        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier($this->getOrAssign($this->callRenames, $node->name->name, '__CALL', $this->callCounter));
            // also fold the class name
            if ($node->class instanceof Node\Name) {
                $node->class = new Node\Name($this->getOrAssign($this->classRenames, $node->class->toString(), '__CLASS', $this->classCounter));
            }
            return;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->toString();
            // keep core functions that change structural meaning of clone
            if (!self::isStructuralFunction($name)) {
                $node->name = new Node\Name($this->getOrAssign($this->callRenames, $name, '__CALL', $this->callCounter));
            }
            return;
        }
        // property access
        if ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier($this->getOrAssign($this->propRenames, $node->name->name, '__PROP', $this->propCounter));
            return;
        }
        if ($node instanceof Node\Expr\NullsafePropertyFetch && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier($this->getOrAssign($this->propRenames, $node->name->name, '__PROP', $this->propCounter));
            return;
        }
        if ($node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
            $node->name = new Node\VarLikeIdentifier($this->getOrAssign($this->propRenames, $node->name->name, '__PROP', $this->propCounter));
            return;
        }
        // class constant fetch and `new X`
        if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
            $node->class = new Node\Name($this->getOrAssign($this->classRenames, $node->class->toString(), '__CLASS', $this->classCounter));
            return;
        }
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $node->class = new Node\Name($this->getOrAssign($this->classRenames, $node->class->toString(), '__CLASS', $this->classCounter));
            return;
        }
        if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
            $node->class = new Node\Name($this->getOrAssign($this->classRenames, $node->class->toString(), '__CLASS', $this->classCounter));
            return;
        }
    }

    /**
     * @param array<string,string> $map
     */
    private function getOrAssign(array &$map, string $original, string $prefix, int &$counter): string
    {
        if (!isset($map[$original])) {
            $map[$original] = $prefix . $counter++;
        }
        return $map[$original];
    }

    /**
     * Functions whose semantics change clustering — keep their names.
     */
    private static function isStructuralFunction(string $name): bool
    {
        static $keep = [
            'isset' => true, 'empty' => true, 'unset' => true,
            'count' => true, 'is_array' => true, 'is_null' => true,
            'is_string' => true, 'is_int' => true, 'is_numeric' => true,
            'array_map' => true, 'array_filter' => true, 'array_reduce' => true,
        ];
        return isset($keep[strtolower($name)]);
    }
}
