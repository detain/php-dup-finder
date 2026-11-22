<?php
declare(strict_types=1);

namespace Phpdup\Util;

use Phpdup\Ml\LegacyStreamPost;

/**
 * Shared JSON POST transport for the two Ml clients (once-S89,
 * php-dup-finder-once-112).
 *
 * Before this file existed, `MlClient::postJson*` and
 * `MlPairClient::postJson*` carried ~70 byte-identical transport lines
 * each — and the twins had ALREADY diverged in the way twins do: the
 * PHP 8.5 magic-variable compat fix (CI-85 leg 5) landed only on the
 * pair client, isolating the `$http_response_header` compiled read
 * behind the lazily-loaded `LegacyStreamPost`, while the cluster client
 * kept the mention in a never-taken ternary — which PHP 8.5 still
 * flags, because the deprecation fires on the COMPILED read itself, at
 * class load, whether or not the branch executes. The collapse fixes
 * that divergence by construction: exactly one stream leg remains and
 * it is the correct one. (The pin here: a token-level census in
 * tests/Unit/Util/HttpTest.php.)
 *
 * Layering: this class is policy-free MECHANICS. The refused-address
 * gate, the CURLOPT_RESOLVE pin computation and the stream rewrite
 * (engine-S19a/S19b policy) live as the statics on the Ml client that
 * owns the SSRF door; the clients feed this transport the already-
 * computed shapes:
 *
 * - `list<string>|null` pins  — MlClient::curlPinEntries() output
 *   (null = pinning refusal; [] = nothing to pin, literal hosts);
 * - `array{url, header, peer_name}|null` — MlClient::pinnedStreamRequest()
 *   output (null = refusal).
 *
 * Failure posture (once-S46 doctrine: a refusal must never look like a
 * success): every transport failure, pinning refusal, non-2xx status
 * and over-cap response yields null — never a truncated or empty body
 * that a caller could mistake for a valid verdict. `MlPairClient`
 * additionally passes its fork-guarded reused handle (engine-S21
 * keep-alive); `MlClient` passes null and gets a fresh handle per call,
 * which is the right economics for one dial per cluster.
 *
 * The response ceiling is a parameter, not a constant here, so the cap
 * policy stays single-sourced with the callers' shared MAX_RESPONSE_
 * BYTES (engine-015) and a later lane can move the constant without
 * touching transport code. Both legs of each client thread the SAME
 * constant — a token census in tests/Unit/Ml/MlPairClientTest.php
 * pins that the pair client can never mint a second ceiling.
 *
 * Deadline honesty (php-dup-finder-once-115, landed by once-S92): the
 * finding's first half was that curl's timeout option is TOTAL while
 * the stream wrapper's 'timeout' is per-read inactivity, so a "total"
 * docblock claim was false whenever ext-curl is absent. Both legs now
 * treat $timeoutSec as a total transfer deadline — curl natively
 * (CURLOPT_TIMEOUT), the stream leg by construction: an in-call
 * deadline loop over the body read where this class owns the handle
 * ({@see self::readStreamWithDeadline()}, PHP >= 8.4 arm), and on
 * every arm a total check that refuses a transfer finishing after the
 * deadline plus a declared-length cross-check that turns an
 * inactivity-aborted partial read into a protocol refusal. A late or
 * cut-short sidecar reply is therefore never surfaced as a plausible
 * verdict (once-S46 honest-fail doctrine).
 */
final class Http
{
    /**
     * curl leg: POST `$body` to `$url` pinned to the gate-checked
     * addresses, capping the response at $maxResponseBytes.
     *
     * @param list<string>|null   $pins      CURLOPT_RESOLVE entries from curlPinEntries(); null = pinning refusal (engine-012 hole c)
     * @param \CurlHandle|null    $curlHandle reused keep-alive handle (pair tier, engine-S21); null = fresh handle for this call
     * @param int                 $timeoutSec total-transfer deadline, enforced in-call by CURLOPT_TIMEOUT on this leg (the stream leg converts the same value at streamPost's documented granularities — once-115, once-S92); <= 0 keeps libcurl's own no-total-bound behavior
     *
     * @return string|null raw response body, or null on refusal/transport error/non-2xx/over-cap
     */
    public static function curlPost(string $url, string $body, ?array $pins, ?\CurlHandle $curlHandle, int $timeoutSec, int $maxResponseBytes): ?string
    {
        if ($pins === null) {
            return null;
        }
        $ch = $curlHandle ?? curl_init();
        if ($ch === false) {
            return null;
        }
        // engine-015 (write-callback cap): the callback owns response
        // collection and aborts the transfer (CURLE_WRITE_ERROR) the
        // moment the body would cross the bound — no RETURNTRANSFER
        // buffering, so a hostile sidecar cannot balloon this worker's
        // RSS.
        $buffer     = '';
        $overflowed = false;
        $opts       = [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            // Refuse silly redirects to file:// / gopher:// / ftp://
            // — only http(s) on the original request is allowed.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION  => function (\CurlHandle $handle, string $chunk) use (&$buffer, &$overflowed, $maxResponseBytes): int {
                if (strlen($chunk) > $maxResponseBytes - strlen($buffer)) {
                    $overflowed = true;
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        // engine-012(c) (pin): preload the DNS cache with the gate-
        // checked records so connect resolves nothing on its own.
        if ($pins !== []) {
            $opts[CURLOPT_RESOLVE] = $pins;
        }
        // engine-S21 (handle economics, now told once): when a reused
        // handle arrives, every per-request option above is re-applied
        // on every call, so no request state (URL, body, write-callback
        // binding) bleeds forward; the only option that could persist
        // is CURLOPT_RESOLVE, and the memoized decision pins the SAME
        // host:port for the client's whole life, so its entries cannot
        // drift to a second authority.
        // CI-85: no curl_close — deprecated in PHP 8.5 and a no-op
        // since 8.0 (the handle closes on GC); a fresh handle closes
        // when this method's local goes out of scope, a reused one when
        // its owning client is dropped.
        curl_setopt_array($ch, $opts);
        $ok       = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($ok !== true || $overflowed) {
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return null;
        }
        return $buffer;
    }

    /**
     * stream leg: POST through the PHP http wrapper with the named
     * host already rewritten to its gate-checked address by
     * pinnedStreamRequest() (the wrapper has no CURLOPT_RESOLVE
     * analogue), carrying the original authority in the pin's explicit
     * Host header and https identity in ssl.peer_name.
     *
     * Total-deadline conversion (php-dup-finder-once-115, once-S92).
     * The wrapper's context 'timeout' bounds each socket operation —
     * connect, and every individual read — NOT the transfer as a
     * whole; a sidecar that drips bytes faster than that window could
     * previously hold a score() call open far past the caller's
     * configured deadline (and a stalled read yielded a PARTIAL body
     * that the checks below used to wave through — probed on PHP 8.3
     * before this change landed). $timeoutSec is therefore enforced
     * here at three granularities:
     *
     * - the PHP >= 8.4 arm hands its own handle to {@see
     *   self::readStreamWithDeadline()}, which aborts the body read
     *   IN-CALL the moment the deadline (or a per-read window) passes;
     * - the pre-8.4 arm runs its single bounded read inside
     *   LegacyStreamPost::post(), whose scope the CI-85 magic-variable
     *   isolation pins closed — a drip there still costs wall-clock
     *   time up to the size cap, but the post-arm total check refuses
     *   anything that COMPLETES after the deadline, so the late reply
     *   never surfaces as a verdict (in-call enforcement on this arm
     *   needs a deadline loop inside the helper: reported as a
     *   follow-up by once-S92; the helper is not writable from this
     *   lane);
     * - both arms cross-check the body against the declared
     *   Content-Length after the read, so an inactivity-aborted
     *   partial becomes an explicit protocol refusal (mirrors
     *   once-S91's length/partial refusal on the update transport).
     *
     * @param null|array{url: string, header: string, peer_name: string|null} $pin pinnedStreamRequest() shape; null = refusal, dial nothing
     * @param int $timeoutSec total-transfer deadline per this method's docblock; <= 0 disables the total bound and keeps the wrapper's per-operation behavior
     *
     * @return string|null raw response body, or null on refusal/transport error/non-2xx/over-cap/truncated/late
     */
    public static function streamPost(?array $pin, string $body, int $timeoutSec, int $maxResponseBytes): ?string
    {
        if ($pin === null) {
            return null;
        }
        // once-S92: the deadline starts at dial time, before the
        // fopen/header phase, so a sidecar that dribbles response
        // headers cannot stack its header dribble on top of a total it
        // already missed.
        $deadlineNs = $timeoutSec > 0
            ? (int) hrtime(true) + $timeoutSec * 1_000_000_000
            : null;
        $ctxOpts = [
            'http' => [
                'method'          => 'POST',
                'header'          => $pin['header'],
                'content'         => $body,
                'timeout'         => $timeoutSec,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
        ];
        if ($pin['peer_name'] !== null) {
            $ctxOpts['ssl'] = ['peer_name' => $pin['peer_name']];
        }
        if (function_exists('http_get_last_response_headers')) {
            // PHP >= 8.5 path: http_get_last_response_headers() replaces the
            // deprecated magic variable.
            $ctx = stream_context_create($ctxOpts);
            $fh  = @fopen($pin['url'], 'r', false, $ctx);
            if ($fh === false) {
                return null;
            }
            // engine-015 (bounded read) + once-S92 (total deadline):
            // pull at most $maxResponseBytes + 1 bytes through a chunk
            // loop that REFUSES — never truncates — when the deadline
            // passes or a read stalls; a longer body is still refused
            // whole by the caller-side over-cap check below.
            $resp = self::readStreamWithDeadline($fh, $maxResponseBytes, $timeoutSec);
            fclose($fh);
            if ($resp === null) {
                return null;
            }
            $headers = (array) http_get_last_response_headers();
        } else {
            // PHP < 8.5 path via LegacyStreamPost. The magic variable MUST
            // NOT be mentioned anywhere in this file: PHP 8.5 deprecates
            // the COMPILED mention itself, so a literal in the never-taken
            // branch still fires on every class load (CI-85 leg 5 — the
            // exact defect php-dup-finder-once-112 caught MlClient still
            // carrying after its twin was fixed). A variable-variable read
            // cannot substitute either (the wrapper materialises the
            // symbol only for direct compiled reads — proven red by the
            // transport tests' pinned-stream pins). This class is
            // referenced solely inside the branch that can never execute
            // on >= 8.5, so PSR-4 lazy loading keeps the literal out of
            // every 8.5 opcache: on 8.3/8.4 the identical fopen+read
            // happens inside the helper's own scope.
            [$resp, $headers] = LegacyStreamPost::post(
                $pin['url'],
                $ctxOpts,
                $maxResponseBytes + 1
            );
            if ($resp === null) {
                return null;
            }
        }
        if ($deadlineNs !== null && (int) hrtime(true) >= $deadlineNs) {
            // The transfer finished after the total deadline. On the
            // >= 8.4 arm the read loop refused in-call already; this
            // is the refusal-granularity net that catches the pre-8.4
            // arm's late-but-complete drip.
            return null;
        }
        $httpCode = self::parseStatusCode($headers);
        if ($httpCode < 200 || $httpCode >= 300) {
            return null;
        }
        if (strlen($resp) > $maxResponseBytes) {
            return null;
        }
        if (self::declaredLengthMismatch($resp, $headers)) {
            return null;
        }
        return $resp;
    }

    /**
     * Chunked bounded read with a total wall-clock deadline: read
     * until EOF (a complete body — at most $maxResponseBytes + 1
     * bytes, so the caller's over-cap check still distinguishes
     * exactly-at-cap from larger), and REFUSE — never truncate — when
     * the deadline passes, a read aborts, or a blocking read returns
     * nothing without EOF. $timeoutSec <= 0 disables the deadline;
     * the loop then behaves like the pre-once-S92 bounded read,
     * governed only by socket defaults.
     *
     * Live in streamPost's PHP >= 8.4 arm, where this class owns the
     * handle; exposed as a seam (and pinned directly by
     * tests/Unit/Ml/MlPairClientTest.php over socketpair fixtures)
     * because the pre-8.4 arm's read lives inside LegacyStreamPost,
     * so the mechanism must stay testable branch-independently until
     * the helper gains its own deadline loop (once-S92 report row).
     *
     * @param resource $stream
     * @internal
     */
    public static function readStreamWithDeadline($stream, int $maxResponseBytes, int $timeoutSec): ?string
    {
        $limit      = $maxResponseBytes + 1;
        $deadlineNs = $timeoutSec > 0
            ? (int) hrtime(true) + $timeoutSec * 1_000_000_000
            : null;
        $buffer = '';
        while (strlen($buffer) < $limit) {
            if ($deadlineNs !== null) {
                $remainingNs = $deadlineNs - (int) hrtime(true);
                if ($remainingNs <= 0) {
                    return null;
                }
                stream_set_timeout($stream, intdiv($remainingNs, 1_000_000_000), intdiv($remainingNs % 1_000_000_000, 1_000));
            }
            $chunk = fread($stream, min(8192, $limit - strlen($buffer)));
            if ($chunk === false) {
                return null;
            }
            if ($chunk === '') {
                if (feof($stream)) {
                    break;
                }
                // A blocking read that returned nothing without EOF
                // stalled (probe on 8.3: the http wrapper surfaces
                // timed-out reads as false or ''-with-timed-out;
                // either shape means no progress to wait for).
                return null;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    /**
     * Protocol-error verdict for a body that does not answer its own
     * declared length (php-dup-finder-once-115, once-S92):
     *
     * - two Content-Length lines, or a non-numeric one, is a framing
     *   lie (request-smuggling class) — mismatch, refuse;
     * - exactly one numeric line must equal the bytes actually read:
     *   an inactivity-aborted stream read returns a short partial,
     *   and that is precisely the shape this check turns into null
     *   instead of a body whose JSON might happen to decode;
     * - absent (chunked/HTTP-1.0-style sidecars) — nothing to
     *   cross-check; status, the size cap and the deadline legs still
     *   apply unchanged.
     *
     * @param array<string> $headers
     */
    private static function declaredLengthMismatch(string $resp, array $headers): bool
    {
        $seen     = 0;
        $declared = 0;
        foreach ($headers as $header) {
            if (preg_match('/^content-length\s*:\s*(.*)$/i', $header, $m) !== 1) {
                continue;
            }
            $seen++;
            if ($seen > 1 || preg_match('/^\d+$/', trim($m[1])) !== 1) {
                return true;
            }
            $declared = (int) $m[1];
        }
        return $seen === 1 && $declared !== strlen($resp);
    }

    /**
     * Extract HTTP status code from a response-header list: the first
     * status line wins, 0 when the headers carry none (the callers'
     * 2xx-window check then refuses — an absent status never parses as
     * success).
     *
     * @param array<string> $headers
     */
    public static function parseStatusCode(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('/^HTTP\/[\d.]+\s+(\d{3})/', $h, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }
}
