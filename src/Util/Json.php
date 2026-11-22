<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * The tool's single JSON serializer.
 *
 * `json_encode()` returns `false` on any failure. Under `declare(strict_types=1)`
 * a `false` handed to a `string` parameter — a report file's contents — aborts
 * the run with a bare `TypeError`, and the `(string)` cast variant collapses it
 * to an empty line written silently into an append-only report. Every report
 * payload here can carry raw filesystem path bytes (`Block::$file`) and observed
 * source literals that are not valid UTF-8: exactly the input that makes a
 * plain `json_encode` return `false`.
 *
 * {@see Json::encode()} centralises the two flags that make that bug class
 * impossible — `JSON_THROW_ON_ERROR` (a failure becomes a loud, named
 * {@see \JsonException}, never a falsy value) and `JSON_INVALID_UTF8_SUBSTITUTE`
 * (invalid bytes become U+FFFD, so a hostile file name degrades to a
 * substituted, still-valid record instead of killing the whole report). Callers
 * pass their own formatting flags; the safety flags are always added on top, so
 * a clean payload serializes byte-for-byte as before.
 */
final class Json
{
    /**
     * Flags forced into every encode. A caller's formatting flags are OR-ed
     * with these and never override or clear them.
     */
    public const SAFETY_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Serialize `$value` to a JSON string, guaranteeing a real string or a
     * thrown {@see \JsonException} — never `false`, never a silent empty write.
     *
     * @param int $flags caller's formatting flags (e.g. `JSON_PRETTY_PRINT`);
     *                   {@see SAFETY_FLAGS} are added automatically.
     * @throws \JsonException when the value has no JSON representation that
     *                        substitution can save (an unencodable resource,
     *                        a reference cycle, or a depth overrun).
     */
    public static function encode(mixed $value, int $flags = 0): string
    {
        try {
            $json = json_encode($value, $flags | self::SAFETY_FLAGS);
        } catch (\JsonException $e) {
            // Re-surface the terse native error as a self-documenting phpdup
            // failure: the previous exception is chained so `--debug` still
            // reaches the original reason.
            throw new \JsonException(
                'phpdup: report payload could not be encoded to JSON ('
                . $e->getMessage() . ') — it holds a value with no JSON representation.',
                (int) $e->getCode(),
                $e,
            );
        }

        // Defensive: JSON_THROW_ON_ERROR makes this unreachable, but the
        // declared return type is string|false, so fail closed rather than
        // let a hypothetical falsy slip into a `string` report writer.
        if ($json === false) {
            throw new \JsonException(
                'phpdup: report payload could not be encoded to JSON (unknown error).',
                JSON_ERROR_NONE,
            );
        }

        return $json;
    }
}
