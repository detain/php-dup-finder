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
 *
 * engine-S15 (php-dup-finder-engine-056 item 1, product story): the
 * case `Ted = 'ted'` was DELETED here. No scoring arm could ever have
 * minted it: candidate generation is bag-driven (the n-gram inverted
 * index proposes pairs, the Jaccard gate admits them) and APTED/TED
 * only ever REFINES pairs Jaccard already found — under first-wins
 * semantics such a pair keeps its 'jaccard' tier, with the TED verdict
 * carried in the edge weight itself (min(jac, ted)). A "tree-edit
 * tier" pair — a match TED originated — is unreachable by this
 * architecture, so the enum promised a tier the detector structurally
 * cannot report. The vocabulary is now exactly the set of tiers the
 * engines emit; the honesty invariant is pinned in tests
 * (TedSizeGateTest::testTierVocabularyMintsNoPhantomTier).
 *
 * Follow-on note: PairScoreWorker::TIER_TED (outside this step's
 * locks) is the stranded fork-side twin of the deleted case — nothing
 * mints it either; a coordinated cleanup should drop it.
 */
enum MatchTier: string
{
    case ExactHash = 'exact-hash';
    case Jaccard = 'jaccard';
    // Item 1.2: the pair passed the jaccard tier but its combined size
    // exceeds max_ted_size, so APTED/TED never judged it — the match is
    // bag-similarity only and is labelled as structurally UNVERIFIED.
    // Mirrored byte-for-byte by PairScoreWorker::TIER_JACCARD_UNVERIFIED
    // (dual-vocabulary drift trap — pinned by equality in tests).
    case JaccardUnverified = 'jaccard-unverified';
    case Containment = 'containment';
    case Ir = 'ir';
    case Ml = 'ml';
}
