<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * Centralized delimiter constants used across fingerprinting, hashing,
 * and AST serialization to ensure a single source of truth and avoid
 * scattered magic characters.
 */
final class Delimiters
{
    /** Joins canonical tokens in SubtreeHasher structural hashes. */
    public const TOKEN_JOIN = "\x1F";

    /** Separates n-gram tokens in NgramFingerprint bag building. */
    public const NGRAM_SEP = "\x1E";

    /** Separates type tag from scalar value in AstSerializer token stream. */
    public const TYPE_SCALAR = '|';

    /** Appends scalar value to type tag inline in AstSerializer. */
    public const TYPE_APPEND = '#';

    /** Joins parts in Hash::ofMany multi-input hashing. */
    public const HASH_JOIN = "\0";
}
