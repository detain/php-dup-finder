<?php
declare(strict_types=1);

namespace Phpdup\Ml;

use JsonException;
use Phpdup\Extraction\Block;

/**
 * HTTP client for an external **pair-similarity** ML scoring
 * service (option 6 of `docs/plans/orm-db-semantic-dedup.md`).
 *
 * Companion to {@see MlClient}, which scores cluster *safety*.
 * MlPairClient scores the **similarity** of a single block pair
 * against a model trained on a labelled corpus that includes
 * ORM ↔ raw-SQL examples. The model gets the feature vector
 * produced by {@see PairFeatures} and returns a single score in
 * `[0, 1]`.
 *
 * **Wire contract**
 *
 *   POST /score-pair
 *   Content-Type: application/json
 *
 *   {
 *     "feature_version":       1,
 *     "structural_hash_match": false,
 *     "ngram_jaccard":         0.42,
 *     "var_jaccard":           0.55,
 *     "call_jaccard":          0.10,
 *     "return_jaccard":        1.00,
 *     "db_tag_jaccard":        1.00,
 *     "ir_token_jaccard":      0.93,
 *     "block_size_ratio":      0.85,
 *     "kind_match":            true,
 *     "block_a_kind":          "method",
 *     "block_b_kind":          "method"
 *   }
 *
 *   200 OK
 *   { "similarity": 0.91, "confidence": 0.78 }
 *
 * **Failure mode**
 *
 * Returns null on any transport error or malformed response so
 * callers fall back to the AST-level scoring tiers without code-
 * path branching. This is the same risk-mitigation pattern as the
 * IR lifter: when the ML service is unavailable, phpdup keeps
 * working with reduced precision rather than failing the run.
 *
 * "Malformed" is enforced strictly since engine-S20 (php-dup-finder-
 * engine-013/014): every verdict number — similarity/confidence in
 * either endpoint, positional or named — must clear the shared
 * closed-[0,1] domain fence {@see MlClient::coerceUnitFloat()} (INF,
 * NaN, booleans, arrays and out-of-domain numbers refuse instead of
 * laundering through a cast), and a batch response is consumed only
 * when its results list is positionally aligned with the request —
 * exact count under sequential keys, because the consumers index it
 * by pair position and cannot see a mid-list shortening.
 *
 * **Security**
 *
 * Every endpoint URL passes {@see MlClient::gateUrl()}, the shared gate
 * rebuilt per php-dup-finder-engine-012: http(s) only, host must be a
 * canonical IP literal or resolve to at least one A/AAAA record
 * (fail-closed), and every candidate address must clear the
 * refused-space bit checks (loopback, RFC 1918, CGNAT, link-local,
 * ULA, IPv4-mapped, multicast/reserved/broadcast, plus the localhost
 * deny-list) documented there. The transports pin the checked
 * addresses and cap response bodies per engine-S19b (see
 * {@see MlClient::curlPinEntries()} / {@see MlClient::pinnedStreamRequest()}
 * and MAX_RESPONSE_BYTES). Callers wanting tighter policy should
 * prefilter the URL.
 */
final class MlPairClient implements PairScorer
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSec = 5,
        private readonly PairFeatures $features = new PairFeatures(),
    ) {
    }

    /**
     * Score a `(A, B)` block pair via the remote model.
     *
     * @return array{similarity: float, confidence: float}|null Null when
     *         disabled, the URL is rejected, or the service returns
     *         malformed data.
     */
    #[\Override]
    public function score(Block $a, Block $b): ?array
    {
        if ($this->baseUrl === '') {
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . '/score-pair';
        $decision = MlClient::gateUrl($url);
        if ($decision === null) {
            return null;
        }

        try {
            $body = json_encode($this->features->extract($a, $b), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $resp = $this->postJson($url, $body, $decision);
        if ($resp === null) {
            return null;
        }
        return $this->parseVerdict($resp);
    }

    /**
     * Decode one /score-pair verdict body into a trusted pair verdict.
     *
     * engine-S20 (php-dup-finder-engine-013): `similarity` and
     * `confidence` pass the shared closed-[0,1] domain fence {@see
     * MlClient::coerceUnitFloat()}. The old bare `(float)` casts
     * laundered `1e999` → INF past the `>= mlPairThreshold` gate and
     * arrays/garbage → 1.0/0.0 as plausible verdicts; now any of those
     * shapes voids the verdict (null) and the pair falls back to the
     * AST tiers — no edge minted on an attacker-made number.
     *
     * @return array{similarity: float, confidence: float}|null
     */
    private function parseVerdict(string $resp): ?array
    {
        try {
            $decoded = json_decode($resp, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $similarity = MlClient::coerceUnitFloat($decoded['similarity'] ?? null);
        $confidence = MlClient::coerceUnitFloat($decoded['confidence'] ?? null);
        if ($similarity === null || $confidence === null) {
            return null;
        }
        return ['similarity' => $similarity, 'confidence' => $confidence];
    }

    /**
     * Score multiple `(A, B)` block pairs in a single HTTP request.
     *
     * Uses the /score-batch endpoint which accepts:
     *   POST /score-batch
     *   { "pairs": [[features_a, features_b], ...] }
     *
     * Server responds:
     *   { "results": [[similarity, confidence], ...] }
     *
     * The results list is CONTRACTUALLY ORDERED: entry i answers pair
     * i (engine-S20, php-dup-finder-engine-014). A response whose
     * results are not a sequential list of exactly count($pairs)
     * entries is voided whole (null) — consumers index it positionally
     * and a mid-list shortening would silently pair one block's
     * features with another pair's verdict.
     *
     * Falls back to null on any error so callers can retry individually.
     *
     * @param list<array{0: Block, 1: Block}> $pairs
     * @return list<array{similarity: float, confidence: float}|null>|null
     *         Null on transport error or malformed response.
     */
    public function scoreBatch(array $pairs): ?array
    {
        if ($this->baseUrl === '' || $pairs === []) {
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . '/score-batch';
        $decision = MlClient::gateUrl($url);
        if ($decision === null) {
            return null;
        }

        $payload = [];
        foreach ($pairs as [$a, $b]) {
            $payload[] = $this->features->extract($a, $b);
        }

        try {
            $body = json_encode(['pairs' => $payload], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $resp = $this->postJson($url, $body, $decision);
        if ($resp === null) {
            return null;
        }

        return $this->parseBatchVerdicts($resp, count($pairs));
    }

    /**
     * Decode a /score-batch response into a positionally aligned list
     * of per-pair verdicts — or null to void the whole response.
     *
     * engine-S20 (php-dup-finder-engine-014, alignment): PairScoreWorker
     * consumes the list as `$batchResults[$idx] ?? null`, which pads a
     * missing TAIL but cannot see a shortened or reordered mid-list —
     * every wrong-position entry then weights an ML-tier edge for the
     * wrong pair. The response is therefore accepted only when
     * `results` is a sequential list (array_is_list) of EXACTLY
     * $expectedCount entries; any other shape returns null so the
     * consumer's honest per-pair retry path re-scores every candidate.
     *
     * engine-S20 (php-dup-finder-engine-013, domain): inside an aligned
     * response each entry's numbers — named or positional — clear the
     * shared closed-[0,1] fence {@see MlClient::coerceUnitFloat()}. A
     * malformed entry degrades to a null slot without voiding its
     * correctly-scored neighbours — the consumer reads
     * $batchResults[$idx] ?? null inside the aligned list, so a null
     * slot simply means that pair receives no ML-tier edge (skip, not
     * retry: per-pair re-scoring fires only when the whole response
     * voids, per the alignment paragraph above).
     *
     * @return list<array{similarity: float, confidence: float}|null>|null
     */
    private function parseBatchVerdicts(string $resp, int $expectedCount): ?array
    {
        try {
            $decoded = json_decode($resp, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
            return null;
        }
        $results = $decoded['results'];
        if (!array_is_list($results) || count($results) !== $expectedCount) {
            return null;
        }

        $verdicts = [];
        foreach ($results as $r) {
            if (!is_array($r) || count($r) < 2) {
                $verdicts[] = null;
                continue;
            }
            $similarity = MlClient::coerceUnitFloat($r['similarity'] ?? $r[0] ?? null);
            $confidence = MlClient::coerceUnitFloat($r['confidence'] ?? $r[1] ?? null);
            $verdicts[] = $similarity !== null && $confidence !== null
                ? ['similarity' => $similarity, 'confidence' => $confidence]
                : null;
        }
        return $verdicts;
    }

    /**
     * POST `$body` as JSON to `$url` and return the raw response body.
     *
     * Uses ext-curl when available (richer error reporting and easier
     * to harden) and falls back to streams when not. Returns null on
     * any transport-level failure, non-2xx HTTP response, pinning
     * refusal, or a response larger than MlClient::MAX_RESPONSE_BYTES.
     * The pinning and cap policy is shared with MlClient (engine-S19b)
     * so the two transports cannot drift.
     *
     * @param array{host: string, port: int, addresses: list<string>} $decision
     * @return string|null Raw response body, or null on transport error or non-2xx response.
     */
    private function postJson(string $url, string $body, array $decision): ?string
    {
        return function_exists('curl_init')
            ? $this->postJsonCurl($url, $body, $decision)
            : $this->postJsonStream($url, $body, $decision);
    }

    /**
     * @param array{host: string, port: int, addresses: list<string>} $decision
     */
    private function postJsonCurl(string $url, string $body, array $decision): ?string
    {
        $pins = MlClient::curlPinEntries($decision);
        if ($pins === null) {
            return null;
        }
        $ch = curl_init();
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
            CURLOPT_TIMEOUT        => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSec,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION  => function (\CurlHandle $handle, string $chunk) use (&$buffer, &$overflowed): int {
                if (strlen($chunk) > MlClient::MAX_RESPONSE_BYTES - strlen($buffer)) {
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
        // CI-85: no curl_close — deprecated in PHP 8.5 and a no-op
        // since 8.0 (the handle closes on GC), which is why the
        // try/finally that carried only that call is gone too.
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
     * @param array{host: string, port: int, addresses: list<string>} $decision
     */
    private function postJsonStream(string $url, string $body, array $decision): ?string
    {
        $pin = MlClient::pinnedStreamRequest($url, $decision);
        if ($pin === null) {
            return null;
        }
        $ctxOpts = [
            'http' => [
                'method'          => 'POST',
                'header'          => $pin['header'],
                'content'         => $body,
                'timeout'         => $this->timeoutSec,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
        ];
        if ($pin['peer_name'] !== null) {
            $ctxOpts['ssl'] = ['peer_name' => $pin['peer_name']];
        }
        $ctx = stream_context_create($ctxOpts);
        $fh  = @fopen($pin['url'], 'r', false, $ctx);
        if ($fh === false) {
            return null;
        }
        // engine-015 (bounded read): pull at most MAX_RESPONSE_BYTES + 1
        // bytes; a longer body is refused without ever being buffered
        // whole. fopen+stream_get_contents replaces file_get_contents
        // purely to pass that length bound — the request itself is the
        // same wrapper operation.
        $resp = stream_get_contents($fh, MlClient::MAX_RESPONSE_BYTES + 1);
        fclose($fh);
        if ($resp === false) {
            return null;
        }
        // CI-85: PHP 8.5 deprecates the $http_response_header magic-
        // variable read; http_get_last_response_headers() replaces it.
        // On 8.3/8.4 the function does not exist and the legacy branch
        // runs byte-identical to the old code.
        $headers  = function_exists('http_get_last_response_headers')
            ? (array) http_get_last_response_headers()
            : $http_response_header;
        $httpCode = $this->parseStatusCode($headers);
        if ($httpCode < 200 || $httpCode >= 300) {
            return null;
        }
        if (strlen($resp) > MlClient::MAX_RESPONSE_BYTES) {
            return null;
        }
        return $resp;
    }

    /**
     * Extract HTTP status code from a $http_response_header array.
     *
     * @param array<string> $headers
     */
    private function parseStatusCode(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('/^HTTP\/[\d.]+\s+(\d{3})/', $h, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }
}
