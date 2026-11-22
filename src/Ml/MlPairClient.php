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
 * The request body of either endpoint is the COMBINED feature vector —
 * the `feature_version` envelope marker followed by EXACTLY the 11
 * feature fields enumerated in {@see PairFeatures::WIRE_KEYS}, in that
 * order (pinned against real extractor output by `MlPairClientTest`;
 * php-dup-finder-once-052 retired the prose that contradicted it):
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
 * /score-batch wraps one such vector per requested pair; see
 * {@see self::scoreBatch()} for its exact request/response shape.
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
 *
 * **Per-instance economics (engine-S21, php-dup-finder-engine-032)**
 *
 * The base URL is fixed at construction, so the two endpoints differ
 * only by path — and the gate decision carries only host, port and the
 * checked addresses. The client therefore computes the gate decision
 * at most ONCE per instance ({@see self::endpointDecision()}), refusals
 * included: the old per-`score()` `dns_get_record` walk is gone from
 * the serial pair path. The curl transport additionally rides ONE
 * reused handle ({@see self::sharedCurlHandle()}) per instance, so
 * keep-alive folds the TCP(+TLS) handshake of later pairs into the
 * first one while the sidecar holds the connection open. Consequences,
 * stated honestly: a sidecar whose DNS moves mid-run stays pinned to
 * the first gate-checked address until a fresh client is constructed
 * (pre-S21 the per-call re-check could follow the move — but under the
 * S19b pin it could never dial an *unchecked* record either way), and
 * the handle is fork-guarded: a client inherited across
 * `pcntl_fork()` re-initialises it in the child, so no curl handle is
 * ever driven by two processes.
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
     * One gate decision per instance (engine-S21). `false` until the
     * first dial resolves it lazily — a client that never scores stays
     * free of any resolver work.
     */
    private bool $gateResolved = false;

    /** @var null|array{host: string, port: int, addresses: list<string>} */
    private ?array $gateDecision = null;

    /**
     * @internal test seam (engine-S21 counting): real MlClient::gateUrl()
     *           runs so far; must stay 1 however many pairs score() and
     *           scoreBatch() dial.
     */
    private int $urlGateCalls = 0;

    /** @var \CurlHandle|null handle owned by the pid in $curlHandlePid */
    private ?\CurlHandle $curlHandle = null;

    private int $curlHandlePid = 0;

    /**
     * The memoized gate decision for this instance's authority.
     *
     * gateUrl() derives host/port/addresses from the URL string alone
     * (the path never enters the decision), so gating the
     * representative `/score-pair` URL decides identically for
     * `/score-batch` — one resolver walk per client, verdict cached.
     *
     * @return null|array{host: string, port: int, addresses: list<string>}
     */
    private function endpointDecision(): ?array
    {
        if (!$this->gateResolved) {
            $this->gateResolved = true;
            $this->urlGateCalls++;
            $this->gateDecision = MlClient::gateUrl(rtrim($this->baseUrl, '/') . '/score-pair');
        }
        return $this->gateDecision;
    }

    /**
     * The instance's single curl handle, (re)initialised as needed.
     *
     * engine-S21: reuse is what turns "fresh TCP/TLS handshake per
     * pair" into one handshake for the instance's lifetime. The fork
     * guard makes the per-fork isolation the PairScoreWorker comment
     * promises structural rather than call-order-dependent: a handle
     * object inherited across pcntl_fork() is discarded here (its fd
     * copy closes in this address space; the forker keeps its own) and
     * the child re-initialises. No curl_close anywhere — GC closes the
     * handle with the client (deprecated in 8.5, no-op since 8.0).
     */
    private function sharedCurlHandle(): ?\CurlHandle
    {
        $pid = (int)\getmypid();
        if ($this->curlHandle !== null && $this->curlHandlePid === $pid) {
            return $this->curlHandle;
        }
        $this->curlHandle = null;
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }
        $this->curlHandle = $ch;
        $this->curlHandlePid = $pid;
        return $ch;
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
        $decision = $this->endpointDecision();
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
     * Uses the /score-batch endpoint. Each `pairs` entry is ONE
     * combined feature vector — the same shape {@see self::score()}
     * POSTs to /score-pair (`feature_version` + the exact 11 fields of
     * {@see PairFeatures::WIRE_KEYS}), listed in request order:
     *   POST /score-batch
     *   { "pairs": [ {<combined vector>}, ... ] }
     *
     * php-dup-finder-once-052: the old text promised nested
     * `[features_a, features_b]` per-block tuples. The extractor has
     * never emitted that shape — the merged vector is the wire, and
     * the request built here is now pinned against the const by
     * `MlPairClientTest::testBatchRequestCarriesOneCombinedVectorPerPair`.
     *
     * Server responds, one entry per requested pair:
     *   { "results": [ [similarity, confidence] | {"similarity":…,"confidence":…}, ... ] }
     *
     * Either entry spelling decodes: a named object, or a positional
     * list whose FIRST TWO elements are similarity and confidence
     * (trailing fields are ignored). An entry that is not an array,
     * carries fewer than two fields, or whose numbers fail the shared
     * fence degrades to a null slot — that pair then receives no
     * ML-tier edge while its neighbours keep their verdicts
     * (engine-S20, php-dup-finder-engine-013).
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
        $decision = $this->endpointDecision();
        if ($decision === null) {
            return null;
        }

        $body = $this->encodeBatchBody($pairs);
        if ($body === null) {
            return null;
        }

        $resp = $this->postJson($url, $body, $decision);
        if ($resp === null) {
            return null;
        }

        return $this->parseBatchVerdicts($resp, count($pairs));
    }

    /**
     * Build the /score-batch request body: `{"pairs": […]}` with one
     * combined {@see PairFeatures} vector per requested pair, in
     * request order. Extracted as a seam so the wire shape the
     * docblock states is assertable without a network round-trip
     * (php-dup-finder-once-052); the bytes are exactly what the
     * inline construction before it produced.
     *
     * @param list<array{0: Block, 1: Block}> $pairs
     */
    private function encodeBatchBody(array $pairs): ?string
    {
        $payload = [];
        foreach ($pairs as [$a, $b]) {
            $payload[] = $this->features->extract($a, $b);
        }

        try {
            return json_encode(['pairs' => $payload], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
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
        // engine-S21 (php-dup-finder-engine-032): one handle per client
        // instance — the second..Nth pair on the serial path rides the
        // keep-alive connection the first one established. Every
        // per-request option below is re-applied on every call, so no
        // request state (URL, body, write-callback binding) bleeds
        // forward; the only option that could persist is
        // CURLOPT_RESOLVE, and the memoized decision pins the SAME
        // host:port for the instance's whole life, so its entries
        // cannot drift to a second authority.
        $ch = $this->sharedCurlHandle();
        if ($ch === null) {
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
        // try/finally that carried only that call is gone too. Since
        // engine-S21 the handle outlives a single exec: GC closes it
        // when the client instance is dropped.
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
