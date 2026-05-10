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
 */
final class PsalmTypeProvider implements TypeProvider
{
    /** @var array<string, array<int, string>> file → line → type */
    private array $index;

    /** @param array<string, array<int, string>> $index */
    public function __construct(array $index)
    {
        $this->index = $index;
    }

    public function typeAt(string $file, int $line): ?string
    {
        return $this->index[$file][$line] ?? null;
    }

    public function name(): string
    {
        return 'psalm';
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException("Psalm report not found: {$file}");
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Psalm report not valid JSON: {$file}");
        }
        return self::fromArray($data);
    }

    /** @param array<int|string, mixed> $report */
    public static function fromArray(array $report): self
    {
        $index = [];
        foreach ($report as $issue) {
            if (!is_array($issue)) continue;
            $file = $issue['file_path'] ?? null;
            $line = $issue['line_from'] ?? null;
            $type = self::extractType($issue);
            if (is_string($file) && is_int($line) && $type !== null) {
                $index[$file][$line] = $type;
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
        $msg = (string)($issue['message'] ?? '');
        if (preg_match('/expects ([a-zA-Z0-9_\\\\\\|<>,\\s\\?]+),/', $msg, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/where ([a-zA-Z0-9_\\\\\\|<>,\\s\\?]+) is expected/', $msg, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
