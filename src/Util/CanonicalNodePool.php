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
 *   - The pool is opt-in: callers explicitly invoke {@see intern()}.
 *     The Normalizer / fingerprint paths don't touch the pool by
 *     default, so existing tests are unaffected.
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
     * Stable identity for a leaf node — type + every scalar attribute.
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
