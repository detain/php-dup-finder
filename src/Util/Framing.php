<?php
declare(strict_types=1);

namespace Phpdup\Util;

use Phpdup\Server\Application;

/**
 * Single ledger of the inter-process wire ceilings and the two
 * primitives that enforce them (once-S74 / php-dup-finder-once-089,
 * the UP-02 carry-over that adopts the hardened frame codec in one
 * shared home instead of a per-channel fork).
 *
 * Two channels speak two dialects: the forked worker pool streams
 * length-prefixed frames (a four-byte big-endian prefix followed by
 * exactly that many serialized payload bytes), and the serve worker
 * speaks newline-terminated request lines. Before this class each
 * channel carried its own discipline: the pool accepted any announced
 * length up to the uint32 range of the prefix field and buffered
 * toward it with no ceiling, and the line reader's bound was a
 * derivation private to the one class that happened to own it. Both
 * are single-source here.
 *
 * The byte format is deliberately unchanged — encode emits exactly the
 * prefix shape the pool has always written — so frames crossing a
 * mixed-version wire still decode, and every S13-era byte pin replays
 * untouched. What is new is refusal: a record larger than the cap
 * fails loud at encode time, and an announced length larger than the
 * cap fails loud at header time, before a single payload byte is
 * buffered. Callers keep their own error vocabulary (worker indices,
 * ACK texts, exit taxonomy) and ask Framing only for the numbers and
 * the enforcement points.
 *
 * Deliberate non-feature: no self-imposed read deadlines live here.
 * shell-S13's doctrine governs both transports — a stuck peer is a
 * lifecycle question answered by the pool's cancel/escalation and the
 * serve layer's timeouts, never laundered into a codec-level clock
 * that would invent a second failure taxonomy.
 */
final class Framing
{
    /**
     * Bytes in the big-endian length prefix of one pool frame.
     * The uint32 range of the field itself (four gigabytes) is a
     * transport fact, not a policy — {@see MAX_FRAME_BYTES} is the
     * policy that keeps a hostile or corrupt announcement from
     * turning into an unbounded parent buffer.
     */
    public const HEADER_BYTES = 4;

    /**
     * Ceiling for one length-prefixed pool frame payload, in bytes,
     * enforced in BOTH directions (encode refuses to emit above it,
     * the drain loop refuses to buffer an announcement above it).
     *
     * Legitimate pool records are per-block, per-pair or per-cluster
     * rows — kilobytes to low megabytes — and the largest frame ever
     * measured anywhere in the fleet is the 35 MiB synthetic
     * slow-reader torture frame the shell-S13 write-window pins
     * replay, so 256 MiB keeps about a seven-fold headroom over every
     * real and benchmarked shape while capping the parent's
     * worst-case residency at workers times the cap. Raising it is a
     * deliberate policy edit, not a widening to dodge a refusal.
     */
    public const MAX_FRAME_BYTES = 256 * 1024 * 1024;

    /**
     * Ceiling for one newline-terminated wire line, shared by the
     * worker's RUN-frame reader and every future serve-side reader of
     * worker lines (the acceptance cap the plan asked both readers to
     * share). Derived from the HTTP body admission ceiling — the
     * +1024 headroom covers the frame decoration many times over —
     * so the two layers cannot silently drift: one number, two
     * readers.
     */
    public const MAX_LINE_BYTES = Application::MAX_BODY_BYTES + 1024;

    /**
     * Frame one pool record into the unchanged wire shape: the
     * four-byte big-endian payload length followed by the serialized
     * payload.
     *
     * @param mixed $record           anything the worker may stream
     * @param int   $maxPayloadBytes  ceiling for the serialized payload;
     *                                defaults to the shared
     *                                {@see self::MAX_FRAME_BYTES} and is
     *                                parameterised ONLY so the boundary
     *                                is unit-provable without allocating
     *                                a cap-sized string (the production
     *                                call site passes no second argument —
     *                                pinned structurally in WorkerPoolTest)
     * @throws \RuntimeException when the payload exceeds the cap, naming
     *                           both numbers: an oversized record is an
     *                           explicit protocol error, never a silent
     *                           unbounded buffer
     */
    public static function encode(mixed $record, int $maxPayloadBytes = self::MAX_FRAME_BYTES): string
    {
        $payload = serialize($record);
        self::checkPayloadLength(strlen($payload), $maxPayloadBytes);

        return pack('N', strlen($payload)) . $payload;
    }

    /**
     * Integer boundary check behind {@see self::encode()} — public so
     * the cap can be pinned at its exact edge without paying for a
     * cap-sized allocation.
     *
     * @throws \RuntimeException naming the announced size and the cap.
     */
    public static function checkPayloadLength(int $bytes, ?int $cap = null): void
    {
        $cap ??= self::MAX_FRAME_BYTES;
        if ($bytes > $cap) {
            throw new \RuntimeException(
                'Framing: refusing to emit a ' . $bytes . '-byte frame payload above the '
                    . $cap . '-byte wire cap — shrink the record or raise the cap deliberately.'
            );
        }
    }

    /**
     * Bounded accumulation of one newline-terminated line (once-S74
     * extraction of the once-S46 discipline, byte-for-byte identical
     * semantics — the worker reader's three refusal arms still live
     * in AnalysisWorker and read this result).
     *
     * A chunk-wise read, never an unbounded `fgets` without a length
     * argument: accumulation stops the moment the delimiter is seen,
     * and can never pass the cap plus one byte of slack, so neither a
     * peer dribbling an endless line nor one announcing more than a
     * line's worth can grow a reader's buffer without bound.
     * `pastCap` distinguishes "refused at the cap" from "peer closed
     * short"; a line whose delimiter lands exactly one byte past the
     * cap content is still complete.
     *
     * @param resource $stream
     * @return array{bytes: string, terminated: bool, pastCap: bool}
     */
    public static function readLineBounded($stream, int $maxBytes): array
    {
        $buffer = '';
        $terminated = false;
        while (strlen($buffer) <= $maxBytes) {
            $chunk = @fread($stream, min(4096, $maxBytes + 1 - strlen($buffer)));
            if ($chunk === false || $chunk === '') {
                break; // peer closed or read error: the sentinel never arrived
            }
            $buffer .= $chunk;
            $newline = strpos($buffer, "\n");
            if ($newline !== false) {
                $terminated = true;
                $buffer = substr($buffer, 0, $newline);
                break;
            }
        }

        return [
            'bytes' => $buffer,
            'terminated' => $terminated,
            'pastCap' => !$terminated && strlen($buffer) > $maxBytes,
        ];
    }
}
