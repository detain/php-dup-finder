<?php

declare(strict_types=1);

namespace Phpdup\Util;

use PhpParser\Node;

/**
 * The single statement-fold key of the pipeline (§5c, improve_logic_info.md).
 *
 * Item 2.1's shared mining substrate and {@see \Phpdup\Refactor\AntiUnifier}'s
 * LCS both key per-statement identity on the SAME function-free token fold:
 * two statements with equal hashes here are equal modulo literals already
 * folded by the active {@see \Phpdup\Normalization\Normalizer} mode. The
 * formula lives exactly once — here — so a fragment run mined worker-side
 * and an anti-unification hole matched pipeline-side cannot drift apart
 * (the §5c "two formulas must not coexist" law).
 *
 * Distinct from {@see \Phpdup\Fingerprint\SubtreeHasher} on purpose: the
 * hasher is position-blind whole-subtree content, this is per-statement
 * with token order. Neither delegates to the other.
 */
final class StmtHash
{
    /**
     * Deterministic per-statement fold key: sha1 over the NUL-joined token
     * stream produced by {@see AstSerializer::tokens()}.
     */
    public static function hash(Node $stmt): string
    {
        return sha1(implode(Delimiters::HASH_JOIN, AstSerializer::tokens($stmt)));
    }
}
