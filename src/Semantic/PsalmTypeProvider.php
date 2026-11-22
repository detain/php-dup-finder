<?php
declare(strict_types=1);

namespace Phpdup\Semantic;

/**
 * Reads a Psalm JSON report and exposes per-(file, line) type lookups.
 *
 * Psalm only emits types as part of issue diagnostics — there's no
 * direct "what's the type at this line" query. We work backwards
 * from the issue stream: if Psalm complained about a mismatch or
 * implicit cast at (file, line), we can extract the expected/actual
 * type string. That covers most lines that have an interesting
 * inferred type; everything else returns null (so the caller falls
 * back to its own heuristics).
 *
 * Usage:
 *   psalm --output-format=json > psalm.json
 *   $provider = PsalmTypeProvider::fromFile('psalm.json');
 *
 * Network / process invocation is intentionally out of scope — the
 * caller decides whether to shell out to psalm or pre-stage a
 * cached report. Tests construct via {@see fromArray()}.
 *
 * Determinism (once-S102, php-dup-finder-once-127 / INTEG-10 — executes
 * the engine-S31a row-5 ruling):
 *  - A (file, line) site reported by issues that DISAGREE on the
 *    extracted type carries no single honest answer — Psalm routinely
 *    reports more than one expected type per line ("Argument 1 …
 *    expects int", "Argument 2 … expects string"). Such a site is
 *    therefore left absent from the index (typeAt → null, caller falls
 *    back) instead of keeping whichever winner arrived last. The index
 *    depends only on the SET of parsed reports, never on their order.
 *  - Reads are typed, never cast: {@see fromFile()} fails loud on an
 *    unreadable file (no silent (string) cast of a false read), and
 *    {@see extractType()} only inspects a message that actually is a
 *    string (a non-string "message" degrades to null, not to an
 *    Array-to-string warning under failOnWarning builds).
 *  - The extraction patterns below are coupled to Psalm's DISPLAY
 *    phrasing, not to a stable machine field — Psalm's JSON carries no
 *    structured "expected type" key. Phrasing drift degrades a site to
 *    null (it never fabricates a type) and surfaces as a red in
 *    tests/Unit/Semantic/{PsalmTypeProviderTest,TypeProviderTest}.php,
 *    which pin both message shapes.
 *
 * Status: NOT WIRED (engine-S31a catalog ledger, row 5). No code
 * path constructs a TypeProvider yet; this reader is held ready for
 * the planned ParameterSynthesizer type-upgrade leg. It only parses
 * a Psalm JSON report (plain array shapes) — it does not link the
 * Psalm library, so the composer-pinned Psalm version does not
 * constrain it.
 */
final class PsalmTypeProvider implements TypeProvider
{
    /** @var array<string, array<int, string>> file → line → sole unambiguous type */
    private array $index;

    /** @param array<string, array<int, string>> $index */
    public function __construct(array $index)
    {
        $this->index = $index;
    }

    #[\Override]
    public function typeAt(string $file, int $line): ?string
    {
        return $this->index[$file][$line] ?? null;
    }

    #[\Override]
    public function name(): string
    {
        return 'psalm';
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException("Psalm report not found: {$file}");
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            throw new \RuntimeException("Psalm report not readable: {$file}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Psalm report not valid JSON: {$file}");
        }
        return self::fromArray($data);
    }

    /** @param array<int|string, mixed> $report */
    public static function fromArray(array $report): self
    {
        // Collect the distinct extracted types per site first: an order-
        // independent set (type-string keyed), so whichever issue arrives
        // first/last cannot mint the winner. A site ends up in the index
        // only when its candidate set holds exactly one type.
        /** @var array<string, array<int, non-empty-array<string, true>>> $candidates */
        $candidates = [];
        foreach ($report as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $file = $issue['file_path'] ?? null;
            $line = $issue['line_from'] ?? null;
            $type = self::extractType($issue);
            if (is_string($file) && is_int($line) && $type !== null && $type !== '') {
                $candidates[$file][$line][$type] = true;
            }
        }

        $index = [];
        foreach ($candidates as $file => $lines) {
            foreach ($lines as $line => $types) {
                if (count($types) === 1) {
                    $index[$file][$line] = (string) array_key_first($types);
                }
                // count($types) > 1: the site genuinely disagrees — it
                // carries no honest answer, so it stays absent (null).
            }
        }

        return new self($index);
    }

    /** @param array<string,mixed> $issue */
    private static function extractType(array $issue): ?string
    {
        // Common patterns in Psalm messages:
        //   "Argument 1 of foo expects int|string, but string provided"
        //   "Cannot return mixed where int is expected"
        $msg = $issue['message'] ?? null;
        if (!is_string($msg)) {
            return null;
        }
        if (preg_match('/expects ([a-zA-Z0-9_\\\\\\|<>,\\s\\?]+),/', $msg, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/where ([a-zA-Z0-9_\\\\\\|<>,\\s\\?]+) is expected/', $msg, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
