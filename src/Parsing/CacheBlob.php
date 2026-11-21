<?php
declare(strict_types=1);

namespace Phpdup\Parsing;

use PhpParser\Node;

/**
 * Storage format and decode screening for the disk caches.
 *
 * {@see AstCache} and {@see TokenCache} hand-rolled the same
 * serialize/unserialize + file dance, and the hardening drifted apart
 * (engine-017 vs engine-018). This class is the single home for the
 * three shared invariants:
 *
 *  - **Length framing.** Every blob is written as `"<byte-length>:<payload>"`.
 *    The header pins the exact payload size, so a truncated or
 *    appended-to file fails the framing check and degrades to a cache
 *    miss — `unserialize()` itself happily ignores trailing bytes
 *    (engine-017b), framing closes that hole.
 *  - **Recursive decode screening.** A tampered blob whose disallowed
 *    class sits in a NESTED slot either raises a (catchable) TypeError
 *    inside `unserialize()` — typed properties, the SEC `nested.txt`
 *    shape — or decodes a `__PHP_Incomplete_Class` into an untyped slot
 *    where it would silently poison downstream hashing. The flat
 *    top-level scan both caches had only caught the second shape one
 *    level deep; {@see self::decode()} catches both (engine-017a), and
 *    since engine-S23b it screens the token stream's plain value
 *    objects the same way, so {@see TokenCache} reaches full parity
 *    (engine-018).
 *  - **Private file mode.** Fresh dirs are 0700 and blobs are written
 *    0600: a cached AST embeds every string literal of the scanned
 *    source, so on a shared runner group-readable cache files leak
 *    secrets and enable cross-user poisoning (engine-020).
 */
final class CacheBlob
{
    /**
     * Node-nesting depth beyond which caching is refused.
     *
     * `serialize()` recurses over the object graph and PHP 8.3 raises a
     * stack-guard Error somewhere around a 3k-4k node chain (measured:
     * 2001 levels serialize fine, 4001 do not). Real-world parsed code
     * sits orders of magnitude below that (max observed over the whole
     * nikic/php-parser library: 23 levels), so 1000 keeps every honest
     * file cacheable while never entering the crash zone.
     */
    public const MAX_DEPTH = 1000;

    private function __construct()
    {
    }

    public static function frame(string $payload): string
    {
        return strlen($payload) . ':' . $payload;
    }

    /**
     * Unwrap a framed payload.
     *
     * @return string|null the payload, or null when the file does not
     *                      match its own length header exactly
     */
    public static function unframe(string $blob): ?string
    {
        $colon = strpos($blob, ':');
        if ($colon === false) {
            return null;
        }
        $header = substr($blob, 0, $colon);
        if ($header === '' || !ctype_digit($header)) {
            return null;
        }
        $start = $colon + 1;
        if (strlen($blob) - $start !== (int)$header) {
            return null;
        }
        return substr($blob, $start);
    }

    /** Write a framed payload and lock the file down to owner-only. */
    public static function write(string $file, string $payload): bool
    {
        if (@file_put_contents($file, self::frame($payload), LOCK_EX) === false) {
            return false;
        }
        @chmod($file, 0o600);
        return true;
    }

    /** Read a file and unwrap its frame; null on any missing/unframable blob. */
    public static function read(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $blob = @file_get_contents($file);
        if ($blob === false) {
            return null;
        }
        return self::unframe($blob);
    }

    /**
     * Decode a payload into a list of $elementClass instances, refusing
     * anything that does not survive screening.
     *
     * Every rejection path degrades to null (a cache miss) rather than
     * an exception — a corrupted or forged cache must never abort a run.
     *
     * Element screening follows the element's nature: {@see Node}
     * elements get the full recursive tree walk; any other admitted
     * element (the token-stream caches' plain value objects) must prove
     * every restored property null-or-scalar, so a class smuggled into
     * a declared or dynamic slot fails closed the same way (engine-018).
     * The restored payload must additionally be a LIST — forged string
     * or sparse-integer keys are refused even when every element screens
     * clean, making the `list<T>` return an enforced fact rather than a
     * promise position-addressed consumers could be harmed by (engine-S23b r1 N-1).
     *
     * @template T of object
     * @param list<class-string> $allowedClasses
     * @param class-string<T> $elementClass
     * @return list<T>|null
     */
    public static function decode(string $payload, array $allowedClasses, string $elementClass): ?array
    {
        try {
            $data = @unserialize($payload, ['allowed_classes' => $allowedClasses]);
        } catch (\Throwable) {
            // Nested disallowed class in a typed slot: unserialize raises
            // "Cannot assign __PHP_Incomplete_Class to property ..." mid-decode.
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        // The list<T> return is enforced, not just promised: position-
        // addressed consumers must never receive a forged map whose
        // elements all screen clean (engine-S23b r1 N-1).
        if (!array_is_list($data)) {
            return null;
        }
        foreach ($data as $element) {
            if (!$element instanceof $elementClass) {
                return null;
            }
            if ($element instanceof Node) {
                if (!self::isCleanTree($element)) {
                    return null;
                }
            } elseif (!self::isScalarOnlyLeaf($element)) {
                return null;
            }
        }
        return $data;
    }

    /**
     * True when every property of a plain value object is null-or-scalar.
     *
     * Shared leaf rule for the object shapes that ride the caches without
     * being Nodes: comment attributes on an AST, and every element of a
     * cached token stream. The cast exposes undeclared (dynamically
     * restored) properties too, which is exactly the slot a hostile blob
     * smuggles through when all declared properties are typed scalars.
     */
    public static function isScalarOnlyLeaf(object $value): bool
    {
        foreach ((array)$value as $leaf) {
            if ($leaf !== null && !is_scalar($leaf)) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when every slot of a decoded AST holds null, a scalar, an
     * array of the same, another real Node, or a real PhpParser comment
     * carrying only null-or-scalar properties of its own.
     *
     * `__PHP_Incomplete_Class` — and any other object that is neither a
     * Node nor a Comment, sneaking through an untyped array or attribute
     * slot — fails the walk. Iterative on purpose: a hostile blob must
     * not be able to blow the stack of the checker that is supposed to
     * survive it.
     */
    public static function isCleanTree(Node $root): bool
    {
        $stack = [$root];
        while ($stack !== []) {
            $value = array_pop($stack);
            if ($value === null || is_scalar($value)) {
                continue;
            }
            if ($value instanceof Node) {
                foreach ($value->getSubNodeNames() as $name) {
                    $stack[] = $value->$name;
                }
                // Attributes are not a sub-node list — a forged blob can
                // hide there, so the screen walks them too (engine-S23a r1 M-1).
                foreach ($value->getAttributes() as $attrValue) {
                    $stack[] = $attrValue;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $stack[] = $item;
                }
                continue;
            }
            if ($value instanceof \PhpParser\Comment) {
                // Real comments ride the attributes as value objects —
                // accept the leaf only after its own properties prove
                // null-or-scalar, or a smuggled dynamic property passes
                // the screen (engine-S23a r3 LOW).
                if (!self::isScalarOnlyLeaf($value)) {
                    return false;
                }
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * True when the node graph nests deeper than $max.
     *
     * Used as the write-side gate so hostile depth never reaches
     * serialize(). Iterative with early exit; the walk costs one linear
     * pass over the AST, far below a cache miss's re-parse.
     *
     * @param array<mixed> $nodes
     */
    public static function exceedsDepth(array $nodes, int $max = self::MAX_DEPTH): bool
    {
        $stack = [];
        foreach ($nodes as $node) {
            $stack[] = [$node, 0];
        }
        while ($stack !== []) {
            [$value, $level] = array_pop($stack);
            if ($value === null || is_scalar($value)) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $stack[] = [$item, $level];
                }
                continue;
            }
            if (!$value instanceof Node) {
                continue; // depth gate, not a validator — decode screens shapes
            }
            $depth = $level + 1;
            if ($depth > $max) {
                return true;
            }
            foreach ($value->getSubNodeNames() as $name) {
                $stack[] = [$value->$name, $depth];
            }
        }
        return false;
    }
}
