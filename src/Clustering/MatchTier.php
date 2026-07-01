<?php
declare(strict_types=1);

namespace Phpdup\Clustering;

/**
 * The similarity tier used to match a pair of blocks.
 *
 * When a pair first passes a scoring phase, it is tagged with that
 * tier. If a later phase improves the score the tier is NOT updated
 * ("first wins" policy) — this preserves which detection mechanism
 * originally found the match.
 */
enum MatchTier: string
{
    case ExactHash = 'exact-hash';
    case Jaccard = 'jaccard';
    case Ted = 'ted';
    case Containment = 'containment';
    case Ir = 'ir';
    case Ml = 'ml';
}
