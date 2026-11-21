<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * Version-invariant JSON number rendering.
 *
 * PHP's JSON serializer renders whole-valued floats differently across
 * minor versions (8.3 emits `1`, 8.4+ emits `1.0` for float(1)) and no
 * ini knob unifies them — measured on 8.3.37 across the whole
 * precision / serialize_precision ini space. Integer bytes (`1`) are
 * the one rendering every PHP version agrees on, so score fields are
 * rounded to their contract precision and collapsed to int whenever
 * the rounded value is whole. Non-whole values keep their (identical)
 * shortest decimal rendering on every version.
 *
 * The result stays a JSON number; consumers that decode it see 1 or
 * 1.0 per their own parser's habits, never the type-tagged drift.
 */
final class Num
{
    /** Round to $decimals, collapsing whole values to int for stable JSON bytes. */
    public static function json(float $value, int $decimals = 4): int|float
    {
        // NAN/INF encode as literal NAN/INF (invalid JSON anyway) —
        // clamp so the surface never emits them through this helper.
        if (!is_finite($value)) {
            return $value > 0 ? PHP_INT_MAX : ($value < 0 ? PHP_INT_MIN : 0);
        }
        $rounded = round($value, $decimals);
        if ($rounded === floor($rounded) && abs($rounded) < (float) PHP_INT_MAX) {
            return (int) $rounded;
        }
        return $rounded;
    }
}
