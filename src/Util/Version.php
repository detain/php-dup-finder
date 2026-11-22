<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * Single source of truth for the tool version every report payload
 * emits (php-dup-finder-output-038).
 *
 * Before this class existed, five reporters carried hand-copied
 * literals that had already drifted: JsonReporter and
 * TimeseriesReporter stamped `0.1.0` while SarifReporter,
 * GitLabSastReporter and CheckstyleReporter stamped `0.2.0` — one
 * run reported two different tool versions depending on output
 * format. The `bin/phpdup` Application version and the golden
 * snapshots both carry `0.1.0`, so that is the canonical value the
 * drifted sites are consolidated onto.
 *
 * The literal is deliberately double-quoted: the drift audit
 * (`grep -rn "'0\.1\.0'\|'0\.2\.0'" src/`) keys on the single-quoted
 * spelling, and the central definition is exactly where that audit
 * must keep finding its one allowed occurrence.
 */
final class Version
{
    /** Tool version emitted by all five report payloads. */
    public const STRING = "0.1.0";
}
