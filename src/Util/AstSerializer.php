<?php
declare(strict_types=1);

namespace Phpdup\Util;

use PhpParser\Node;

/**
 * Serializes a PhpParser AST to a deterministic token stream.
 *
 * The token stream is what fingerprints and hashes consume. It captures
 * the node shape in pre-order traversal: every node emits a "type tag"
 * plus, for leaf-ish nodes, the relevant scalar (variable name, literal
 * value, identifier text). Composite nodes emit a paren-balanced
 * structure so structurally distinct trees never collide.
 *
 *   tokens(if($x>0){f($x);}) =
 *     ["If_(", "BinaryOp_Greater_(", "Variable_$x", "Int_0", ")",
 *      "FuncCall_(", "Name_f", "Variable_$x", ")", ")"]
 */
final class AstSerializer
{
    /** @return list<string> */
    public static function tokens(Node $node): array
    {
        $out = [];
        self::walk($node, $out);
        return $out;
    }

    public static function nodeCount(Node $node): int
    {
        // engine-004 counterparty: the count used to recurse per descendant
        // node, and a 10k-deep array literal (legal PHP — the parser itself
        // descends iteratively) segfaulted the worker here before the
        // BlockExtractor size ceiling could reject the oversized node.
        // Iterative stack walk; the count is byte-for-byte what countWalk()
        // produced. tokens()/walk() stays recursive on purpose: it only ever
        // runs on blocks the maxSize ceiling (800 nodes) already admitted,
        // so its depth is bounded by construction.
        $count = 0;
        $stack = [$node];
        while ($stack !== []) {
            $current = array_pop($stack);
            ++$count;
            foreach ($current->getSubNodeNames() as $sub) {
                $val = $current->$sub;
                if ($val instanceof Node) {
                    $stack[] = $val;
                } elseif (is_array($val)) {
                    foreach ($val as $v) {
                        if ($v instanceof Node) {
                            $stack[] = $v;
                        }
                    }
                }
            }
        }
        return $count;
    }

    /** @param list<string> $out */
    private static function walk(Node $node, array &$out): void
    {
        $type = self::shortType($node);

        $scalar = self::scalarPart($node);
        if ($scalar !== null) {
            $out[] = $type . Delimiters::TYPE_SCALAR . $scalar;
            return;
        }

        $hasChildren = false;
        $children = [];
        foreach ($node->getSubNodeNames() as $sub) {
            $val = $node->$sub;
            if ($val instanceof Node) {
                $children[] = $val;
                $hasChildren = true;
            } elseif (is_array($val)) {
                foreach ($val as $v) {
                    if ($v instanceof Node) {
                        $children[] = $v;
                        $hasChildren = true;
                    }
                }
            } elseif (is_scalar($val)) {
                // structurally significant — append to type tag
                $type .= Delimiters::TYPE_APPEND . self::stringifyScalar($val);
            }
        }

        if (!$hasChildren) {
            $out[] = $type;
            return;
        }
        $out[] = $type . '(';
        foreach ($children as $c) {
            self::walk($c, $out);
        }
        $out[] = ')';
    }

    public static function shortType(Node $node): string
    {
        $cls = $node::class;
        $prefix = 'PhpParser\\Node\\';
        if (str_starts_with($cls, $prefix)) {
            return str_replace('\\', '_', substr($cls, strlen($prefix)));
        }
        return $cls;
    }

    /**
     * Returns the leaf-scalar representation of nodes that carry meaning
     * in their scalar value (literals, variable names, identifiers,
     * names). Returns null for composite nodes whose meaning is in
     * children.
     */
    public static function scalarPart(Node $node): ?string
    {
        // Scalar literals
        if ($node instanceof Node\Scalar\String_)        return 's:' . $node->value;
        if ($node instanceof Node\Scalar\Int_)           return 'i:' . $node->value;
        // Floats must never ride the ini-driven decimal rendering
        // (precision / serialize_precision differ across PHP builds and
        // minor versions) — they enter the stream as IEEE-754 bytes.
        if ($node instanceof Node\Scalar\Float_)         return 'f:' . self::floatBytes($node->value);
        if ($node instanceof Node\Scalar\InterpolatedString) {
            $parts = '';
            foreach ($node->parts as $p) {
                if ($p instanceof Node\InterpolatedStringPart) {
                    $parts .= 'L:' . $p->value . ';';
                } else {
                    $parts .= 'X;';
                }
            }
            return 'is:' . $parts;
        }

        // Variables and identifiers
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return 'v:' . $node->name;
        }
        // Identifier covers VarLikeIdentifier (its subclass); both serialise via the 'id:' prefix here.
        if ($node instanceof Node\Identifier) {
            return 'id:' . $node->name;
        }
        if ($node instanceof Node\Name) {
            return 'n:' . $node->toString();
        }

        return null;
    }

    /**
     * @param scalar $v
     */
    private static function stringifyScalar(mixed $v): string
    {
        if (is_bool($v))   return $v ? 'true' : 'false';
        if (is_null($v))   return 'null';
        if (is_int($v))    return (string)$v;
        if (is_float($v))  return self::floatBytes($v);
        return (string)$v;
    }

    /**
     * Canonical float identity: the 16 hex digits of the big-endian
     * IEEE-754 double (NaN normalized to a single fixed pattern because
     * payload bits are platform-dependent), then an F-prefix for stable
     * decimals and infinite values. Byte-stable across PHP versions,
     * inis and libc by construction (unlike any decimal rendering).
     */
    private static function floatBytes(float $v): string
    {
        if (is_nan($v)) {
            return 'xnan';
        }
        if (is_infinite($v)) {
            return $v > 0 ? 'FINF' : 'F-INF';
        }
        $packed = pack('E', $v);
        return 'F' . strtoupper(bin2hex($packed));
    }
}
