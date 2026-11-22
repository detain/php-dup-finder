<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * Shared control-byte boundary for every report surface that embeds
 * scanned name text into line-oriented or quoted output formats
 * (php-dup-finder-output-011 / -047 / -052).
 *
 * Paths arrive verbatim from the filesystem. A scanned name can carry the
 * four bytes that break every such format: NUL (system-call truncation),
 * LF and CR (they forge standalone stream lines — `--- /dev/null`, `+++ b/…`
 * and `@@ … @@` hunks inside a patch, arbitrary statements after a split
 * quoted label in DOT/PlantUML, broken line framing in Prometheus
 * exposition), and ESC (it drives the terminal of anyone who cats the
 * artifact). A crafted newline-in-dirname tree made `patch -p1` CREATE
 * attacker-named files from phpdup's own .patch output (SEC-03 proof).
 *
 * Two arms, one per format capability:
 *
 *  - reject() — surfaces where the byte would forge stream structure and no
 *    faithful in-band escape exists: unified-diff headers, patch member
 *    anchors, PlantUML labels (its grammar carries no quote escape). It
 *    fails loud at report-build time with a named diagnostic, and the
 *    whole surface refuses: the ScriptKindApplyRefusal posture — a partial
 *    artifact set masquerading as the complete one is the misfeed class.
 *  - substitute() — formats that carry label text (Graphviz quoted strings,
 *    Prometheus label values): the unescapable residue becomes one visible
 *    replacement character, never a silent whole-value drop.
 *
 * Doctrine alignment: this is the same byte-class discipline the
 * TimeseriesReporter git-ref guard family introduced (e00c85f7 — control
 * bytes rejected wholesale at the boundary, never laundered). This class is
 * the central venue; consumers (output-S19 terminal scrub, -S20 label
 * reuse, -S22 shared helper, -S35) build on it instead of forking a second
 * sanitizer.
 *
 * The byte set is exactly the plan's `\r \n \0 \x1b`: tabs and spaces are
 * legal filename bytes and stay untouched.
 */
final class ControlText
{
    /** The four stream-forging / terminal-driving bytes (one string char each). */
    public const FORGE_BYTES = "\0\n\r\x1b";

    /** Visible marker substituted for a byte a quoted format cannot faithfully carry. */
    public const SUBSTITUTE = "\u{FFFD}";

    /** @var array<int,string> ord => human byte name used in diagnostics */
    private const NAMES = [
        0x00 => 'NUL byte (0x00)',
        0x0A => 'newline (0x0A)',
        0x0D => 'carriage return (0x0D)',
        0x1B => 'escape byte (0x1B)',
    ];

    /** @var array<string,string> forge byte => printable hex stand-in for messages */
    private const HEX = [
        "\0"  => '\x00',
        "\n"  => '\x0A',
        "\r"  => '\x0D',
        "\x1b" => '\x1B',
    ];

    /**
     * First forging control byte in $text, or null when the text is safe to
     * embed in any line-oriented or quoted report stream.
     */
    public static function firstForgeByte(string $text): ?int
    {
        $index = strcspn($text, self::FORGE_BYTES);
        return $index < strlen($text) ? ord($text[$index]) : null;
    }

    public static function containsForgeBytes(string $text): bool
    {
        return self::firstForgeByte($text) !== null;
    }

    public static function forgeByteName(int $byte): string
    {
        return self::NAMES[$byte] ?? sprintf('control byte (0x%02X)', $byte);
    }

    /**
     * Reject arm: throw the named diagnostic when $text carries a forging
     * control byte. $surface names the emitting class, $what names the
     * embedded field (e.g. 'member file path'), so the message alone tells
     * an operator which value to fix.
     *
     * @throws \RuntimeException naming surface, field, byte and visible text
     */
    public static function reject(string $text, string $surface, string $what): void
    {
        $byte = self::firstForgeByte($text);
        if ($byte === null) {
            return;
        }
        throw new \RuntimeException(sprintf(
            'phpdup: %s refused to embed %s containing a %s: %s — such a value forges stream lines in the '
            . 'generated artifact (patch/diff header, label closure, exposition line). Rename the file or exclude the path.',
            $surface,
            $what,
            self::forgeByteName($byte),
            self::visible($text),
        ));
    }

    /**
     * Scrub arm: replace every forging byte with one visible replacement
     * character. For quoted-label formats that have no faithful escape for
     * the byte; the label stays on its line and the audit reading never
     * sees forged structure. Clean text passes through byte-identical.
     */
    public static function substitute(string $text): string
    {
        return strtr($text, array_fill_keys(str_split(self::FORGE_BYTES), self::SUBSTITUTE));
    }

    /** Render forging bytes as printable hex stand-ins for messages. */
    public static function visible(string $text): string
    {
        return strtr($text, self::HEX);
    }
}
