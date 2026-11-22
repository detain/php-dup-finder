<?php
declare(strict_types=1);

namespace Phpdup\Util;

use PhpParser\Node;

/**
 * Serializes a PhpParser AST to a deterministic, prefix-free token stream.
 *
 * The token stream is what fingerprints and hashes consume. It captures
 * the node shape in pre-order traversal: every node emits a "type tag"
 * plus, for leaf-ish nodes, the relevant scalar (variable name, literal
 * value, identifier text). Composite nodes emit a paren-balanced
 * structure so structurally distinct trees never collide.
 *
 *   tokens(if($x>0){f($x);}) =
 *     ["Stmt_If_(", "Expr_BinaryOp_Greater(", "Expr_Variable|v:x", …]
 *
 * **Prefix-free framing (php-dup-finder-engine-037, r1 F-1 addendum).**
 * Free-text scalar values (string literals, interpolated-string literal
 * parts, variable / identifier / name payloads, appended string
 * attributes) may carry the stream's own delimiter bytes: \x1F joins
 * tokens into the structural hash, \x1E joins tokens into n-grams, \x00
 * joins them in the statement-alignment channel (AntiUnifier::stmtHash,
 * Hash::ofMany, via Delimiters::HASH_JOIN), and '|' / '#' delimit the
 * type tag from the scalar. Un-framed, a literal's bytes spliced forged
 * tokens into the joined stream — two reviewers independently produced
 * byte-identical structural hashes for different code (PD-07 / FUZZ-04
 * collision pairs), defeating the tool's strongest claim: equal hash ⇒
 * equal code. The fix frames every free-text value: a value containing
 * none of the framing bytes ({@see FRAME_MARK}, TOKEN_JOIN, NGRAM_SEP —
 * plus ';' inside `L:` segments, which are list-terminated) passes
 * through byte-identical; any other value is emitted as
 * `FRAME_MARK . strlen . ':' . raw`, a length prefix that makes the
 * segment self-delimiting regardless of the bytes it carries. The
 * stream is then uniquely decodable left-to-right (a FRAME_MARK byte
 * can only begin a frame; the frame body is skipped by length), so
 * distinct token lists can never join to the same byte string.
 *
 * Clean values — every literal in every non-adversarial corpus — are
 * byte-identical to the pre-framing stream, so structural hashes, cache
 * identities and goldens of honest code do not move on this leg. The
 * format-change roster bump landed with engine-S34b: the stream's cache
 * identity is {@see \Phpdup\Fingerprint\FingerprintIdMode::CACHE_IDENTITY}
 * ('cachekey-v1-framing-nul', mirrored in the root
 * {@see \Phpdup\Fingerprint\FingerprintIdMode::ROOT_TOKEN_FILE} file),
 * and the golden regen on that step came back zero-diff exactly as the
 * freeze law predicts — confirmatory, not corrective.
 *
 * The '|' (type|scalar) and '#' (type#append) channels rest on a
 * php-parser v5 invariant: a node carries at most one free-text sub-node
 * value — further appends are bool/int renderings and the float form,
 * none of which can contain '#'. A node class that ever ships two
 * string-valued sub-nodes would forge across the '#' boundary unframed
 * and must widen the danger set (engine-S34b rider β: re-open here, not
 * silently in the stores).
 */
final class AstSerializer
{
    /**
     * ASCII "group separator" opening a length frame. It is deliberately
     * outside the join/gram delimiters and outside anything the grammar
     * uses itself, and safe (un-framed) values never contain it — which
     * is what makes a frame unambiguously recognizable mid-token.
     */
    public const FRAME_MARK = "\x1D";

    /**
     * Bytes that force framing: the mark itself plus every joiner a token
     * list can be spliced with — the structural stream (\x1F), the n-gram
     * stream (\x1E), and the NUL joiner of the statement-alignment hashes
     * (\0, AntiUnifier::stmtHash / Hash::ofMany). An unframed NUL inside a
     * value re-opened exactly the boundary-forgery this whole leg closes
     * (r1 F-1); honest corpus values carry none of these bytes, so the
     * widened set moves no clean digest (pinned, not assumed).
     */
    private const FRAME_DANGER = "\x00\x1D\x1E\x1F";

    /**
     * Frame one free-text scalar value for the stream (see class docblock).
     *
     * @param string $extraDanger additional bytes that must force framing
     *                            in this position (e.g. the `L:` segment
     *                            terminator ';')
     */
    private static function frame(string $value, string $extraDanger = ''): string
    {
        $danger = self::FRAME_DANGER . $extraDanger;
        if (strpbrk($value, $danger) === false) {
            return $value;
        }
        return self::FRAME_MARK . strlen($value) . ':' . $value;
    }

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
        if ($node instanceof Node\Scalar\String_)        return 's:' . self::frame($node->value);
        if ($node instanceof Node\Scalar\Int_)           return 'i:' . $node->value;
        // Floats must never ride the ini-driven decimal rendering
        // (precision / serialize_precision differ across PHP builds and
        // minor versions) — they enter the stream as IEEE-754 bytes.
        if ($node instanceof Node\Scalar\Float_)         return 'f:' . self::floatBytes($node->value);
        if ($node instanceof Node\Scalar\InterpolatedString) {
            $parts = '';
            foreach ($node->parts as $p) {
                if ($p instanceof Node\InterpolatedStringPart) {
                    // ';' is a segment terminator inside the list, so it
                    // must force framing too (engine-037: "a;L:b" forged a
                    // two-segment list from one value).
                    $parts .= 'L:' . self::frame($p->value, ';') . ';';
                } else {
                    $parts .= 'X;';
                }
            }
            return 'is:' . $parts;
        }

        // Variables and identifiers
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return 'v:' . self::frame($node->name);
        }
        // Identifier covers VarLikeIdentifier (its subclass); both serialise via the 'id:' prefix here.
        if ($node instanceof Node\Identifier) {
            return 'id:' . self::frame($node->name);
        }
        if ($node instanceof Node\Name) {
            return 'n:' . self::frame($node->toString());
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
        // Remaining scalars are free text (e.g. InlineHTML's value) and
        // ride the same frame as scalarPart's payloads (engine-037).
        return self::frame((string)$v);
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
