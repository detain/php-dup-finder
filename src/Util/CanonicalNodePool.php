<?php
declare(strict_types=1);

namespace Phpdup\Util;

use PhpParser\Node;

/**
 * Process-wide pool of canonicalised AST subtrees keyed by structural
 * hash. After normalisation, identical subtrees are deduplicated to a
 * single shared instance — millions of `Node\Expr\Variable` objects
 * with identical `name = '__V0'` collapse into one.
 *
 * Intentional caveats:
 *
 *   - The pool only deduplicates *leaf* nodes. Composite nodes (with
 *     children) are not pooled because mutating one would corrupt the
 *     others. This is the conservative choice; copy-on-write is a
 *     follow-up.
 *   - Leaf identity includes the class, every scalar child value, AND
 *     every SCALAR ATTRIBUTE (engine-008). Parser-painted positions —
 *     startLine/endLine/token/file offsets — are scalar attributes, so
 *     leaves from distinct positions never alias a shared instance and
 *     per-member line reporting in JSON/`--apply` cannot be corrupted
 *     by pooling. Nodes minted fresh by canonicalisation (the name
 *     stamps `__CALL_*`/`__PROP_*`/`__CLASS_*`, `__FN`, the fold
 *     sentinels) carry no attributes, so they dedupe corpus-wide —
 *     which is where the RSS win actually lives. Non-scalar attributes
 *     (comment lists) are not part of the key: they are decoration,
 *     and deep clone shares those objects by reference already.
 *   - The pool is opt-in: callers explicitly invoke {@see intern()}.
 *     Normalizer splices leaves into their parent slots when
 *     constructed with lowMemory + a pool (engine-008: the --low-memory
 *     mode without splicing was inert and net-negative). The
 *     fingerprint paths don't touch the pool by default, so existing
 *     tests are unaffected.
 *   - Pooled instances are shared across every block that interned the
 *     key, so canonical trees are read-only after normalisation (the
 *     pipeline's consumers — hashers, fingerprints, TED — are; hole
 *     rewriting runs on the original AST).
 *   - {@see clear()} is provided so long-lived processes (TUI,
 *     watch-mode) can reset the pool between runs.
 */
final class CanonicalNodePool
{
    /** @var array<string, Node> hash → shared node instance */
    private array $pool = [];

    /**
     * Return a shared instance for the given leaf node, or the
     * original node when it has children (in which case the call is
     * a no-op).
     */
    public function intern(Node $node): Node
    {
        if (self::hasChildren($node)) {
            return $node;
        }
        $key = self::leafKey($node);
        if (!isset($this->pool[$key])) {
            $this->pool[$key] = $node;
        }
        return $this->pool[$key];
    }

    public function size(): int
    {
        return count($this->pool);
    }

    public function clear(): void
    {
        $this->pool = [];
    }

    private static function hasChildren(Node $node): bool
    {
        foreach ($node->getSubNodeNames() as $sub) {
            $val = $node->$sub;
            if ($val instanceof Node) return true;
            if (is_array($val)) {
                foreach ($val as $v) {
                    if ($v instanceof Node) return true;
                }
            }
        }
        return false;
    }

    /**
     * Stable identity for a leaf node — type + every scalar child value
     * + every scalar attribute.
     *
     * The attribute half is the engine-008 line-honesty fix: the old key
     * ignored attributes, so an interned leaf aliased the FIRST-seen
     * node's startLine/endLine/token positions, and any consumer that
     * reported lines off the shared instance would corrupt per-member
     * JSON/`--apply` line reporting. Position attributes are parser-
     * painted scalars, so including every scalar attribute keeps equal-
     * position leaves sharing (the canonicalisation-minted sentinels all
     * carry an empty attribute set and dedupe freely) and strictly
     * separates every pair that differs in any line-bearing or
     * value-bearing attribute. Non-scalar attributes (the `comments`
     * list) are decoration already shared by reference under clone, so
     * they are excluded rather than paying a per-comment serialization.
     *
     * Attribute iteration order is deterministic: every node in a parsed
     * or canonicalised tree receives its keys from the same construction
     * site (Parser painting, or NodeAbstract::__construct's merge), so
     * equal-valued maps serialize to equal strings without sorting.
     */
    private static function leafKey(Node $node): string
    {
        $parts = [$node::class];
        foreach ($node->getSubNodeNames() as $sub) {
            $val = $node->$sub;
            if (is_scalar($val) || $val === null) {
                $parts[] = $sub . '=' . self::scalarRepr($val);
            }
        }
        foreach ($node->getAttributes() as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parts[] = '@' . $key . '=' . self::scalarRepr($value);
            }
        }
        return implode('|', $parts);
    }

    private static function scalarRepr(mixed $v): string
    {
        if ($v === null)        return 'null';
        if (is_bool($v))        return $v ? 'true' : 'false';
        if (is_int($v))         return 'i' . $v;
        if (is_float($v))       return 'f' . $v;
        if (is_string($v))      return 's' . strlen($v) . ':' . $v;
        return '?';
    }
}
