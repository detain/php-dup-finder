<?php
declare(strict_types=1);

namespace Phpdup\Fingerprint;

use PhpParser\Node;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\Hash;

/**
 * Computes a stable structural hash over a canonicalized AST.
 *
 * Two blocks share a structural hash iff their canonical token streams
 * are byte-identical. Combined with aggressive normalization that
 * means: same node shape, same literals (modulo placeholders), same
 * variable scoping pattern, same call/property scheme.
 */
final class SubtreeHasher
{
    public function hash(Node $node): string
    {
        $tokens = AstSerializer::tokens($node);
        return Hash::of(implode("\x1F", $tokens));
    }
}
