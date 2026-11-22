<?php
declare(strict_types=1);

namespace Phpdup\Ml;

use JsonException;
use Phpdup\Extraction\Block;
use Phpdup\Util\Http;

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
 * The same contract, told sidecar-facing (both routes, batch
 * alignment, the 1 MiB cap and the pinned-transport posture), lives
 * in docs/ML.md and the "ML pair-scoring sidecar contract" section of
 * docs/SERVER.md; tools/ml-sidecar-example.php is a runnable reference
 * server for it.
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
 * URL validation is split in two doors (once-S54, php-dup-finder-
 * once-076/083/084). Config ADMISSION validates SHAPE ONLY through
 * {@see self::pairUrlAdmissionError()}: scheme, host grammar,
 * canonical-literal bracket pairing, numeric shorthands, and the
 * refused-address bit matrix — all WITHOUT touching a resolver, so a
 * planted `.phpdup.json` cannot turn config validation into a
 * blocking DNS lookup (stall or exfil channel). Hostnames are
 * deferred to dial time. The DIAL door {@see
 * self::pairEndpointDecision()} then performs the one resolver walk
 * per client (memoized, engine-S21 economics kept) and fail-closes
 * on zero records or any refused record — including the NAT64
 * 64:ff9b::/96 embedded-IPv4 recursion that the shared
 * {@see MlClient::gateUrl()} matrix predates and does not classify.
 * By default the refused space is the full engine-012 list; the
 * opt-in `ml_pair_allow_private` (CLI: --ml-pair-allow-private,
 * default off, root-config only) admits the PRIVATE families
 * (loopback, RFC 1918, CGNAT, link-local, ULA, embedded copies
 * thereof) for the documented local-sidecar topology, while the
 * non-private reserved spaces (0.0.0.0/8, 192.0.0.0/24,
 * 198.18.0.0/15, multicast/reserved) stay refused either way. The
 * transports pin the checked addresses and cap response bodies per
 * engine-S19b (see {@see MlClient::curlPinEntries()} / {@see
 * MlClient::pinnedStreamRequest()} and MAX_RESPONSE_BYTES); since
 * once-S89 both Ml clients run their POST legs through the single
 * shared transport in {@see \Phpdup\Util\Http}, so the curl/stream
 * mechanics that had drifted apart between the twins cannot drift
 * again; and since once-S92 both transports enforce the instance's
 * timeout as a TOTAL transfer deadline (php-dup-finder-once-115) —
 * a stalled, dripping, or length-lying sidecar reply is refused
 * whole, falling back to the AST tiers instead of being waited on
 * indefinitely or laundered upward as a short body. Callers
 * wanting tighter policy should prefilter the URL.
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
    /**
     * @param string $baseUrl Sidecar URL prefix ('' = disabled).
     * @param int    $timeoutSec TOTAL transfer deadline per endpoint call,
     *        enforced by BOTH transports (php-dup-finder-once-115,
     *        landed by once-S92): natively in-call on curl
     *        (CURLOPT_TIMEOUT) and on the stream leg by
     *        {@see \Phpdup\Util\Http::streamPost()}'s deadline
     *        machinery — in-call body-read loop on the PHP >= 8.4
     *        arm, refusal-granularity total + declared-length
     *        cross-check on every arm. A reply that is late or cut
     *        short therefore yields a null verdict (fallback to the
     *        AST tiers), never a slow-but-successful or truncated
     *        one. <= 0 keeps the transports' own per-operation
     *        defaults with no total bound.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSec = 5,
        private readonly PairFeatures $features = new PairFeatures(),
        // once-S54: admit sidecar URLs in the PRIVATE address families
        // (loopback, RFC 1918, CGNAT, link-local, ULA) — the documented
        // local-sidecar topology. Default false keeps the full engine-012
        // refusal posture ([OWNER] ruling); reserved/non-routable spaces
        // stay refused even when this is true.
        private readonly bool $allowPrivate = false,
    ) {
    }

    /**
     * Config-admission door for the pair tier: validate SHAPE, never DNS.
     *
     * once-S54 (php-dup-finder-once-076/083/084). The old admission
     * reused {@see MlClient::isAllowedUrl()}, which resolves hostnames
     * fail-closed at check time — turning every `phpdup analyze` /
     * `--validate-config` over an attacker-planted `.phpdup.json` into
     * a blocking resolver query to an attacker-chosen name (a
     * DNS-exfil / stall channel that fires long before any pair is
     * scored), and refusing the documented `127.0.0.1` / RFC 1918
     * sidecar topology with a message naming only `0.0.0.0`.
     *
     * This door performs ZERO resolver calls by construction (no
     * dns_get_record / gethostbynamel / gateUrl reachable from this
     * method — pinned structurally by tests/Unit/Cli/
     * MlPairUrlValidationTest) and decides four things:
     *
     * - http(s) scheme only; explicit non-empty host;
     * - canonical IP literals carry the full refused-space bit matrix
     *   under the `allowPrivate` policy (0/8, RFC 1918, CGNAT,
     *   loopback, link-local, 192.0.0/24, 198.18/15, multicast/
     *   reserved, plus IPv4 embedded in ::/96, ::ffff:/96 and the
     *   NAT64 64:ff9b::/96 well-known prefix — the last class
     *   php-dup-finder-once-083 caught the shared matrix missing);
     * - `localhost` / `*.localhost` are refused by name without
     *   resolution unless opted in;
     * - bare hostnames are grammar-checked only. Resolution — with the
     *   same bit matrix over every record, fail-closed on zero
     *   records — happens once at DIAL time inside the client
     *   ({@see self::pairEndpointDecision()}), where the result is
     *   also the pin the transport connects to. A name that refuses
     *   late costs one bounded transport timeout, never a stalled
     *   validation run.
     *
     * inet_aton numeric shorthands (`2130706433`, `0x7f000001`,
     * `127.1`, `0177.0.0.1`) are refused in BOTH policies: they are a
     * canonicality hazard (gate-vs-transport divergence,
     * php-dup-finder-engine-012), not a privacy question.
     *
     * @return null|string Null on admission, or a reason sentence that
     *         NAMES the refused space and the opt-in key
     *         (`ml_pair_allow_private`, CLI: --ml-pair-allow-private)
     *         when a policy refusal is what rejected the URL.
     */
    public static function pairUrlAdmissionError(string $endpointUrl, bool $allowPrivate = false): ?string
    {
        $parts = parse_url($endpointUrl);
        if (!is_array($parts)) {
            return 'ml_pair_url is not a parseable URL';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $shown = $scheme === '' ? 'no scheme' : "scheme '$scheme'";
            return "ml_pair_url must be an http(s) URL (got $shown)";
        }
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            return 'ml_pair_url must have a non-empty host';
        }
        $bracketed = str_starts_with($host, '[');
        if ($bracketed) {
            // Strip IPv6 brackets — parse_url keeps them (e.g. '[::1]').
            $host = trim($host, '[]');
        }
        if ($host === '') {
            return 'ml_pair_url must have a non-empty host';
        }
        $host = strtolower($host);

        // RFC 6761 special-use names: decided by name, never by resolver.
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return $allowPrivate
                ? null
                : 'ml_pair_url targets the localhost special-use name, refused by default policy; '
                    . self::optInGuidance();
        }

        $bytes = inet_pton($host);
        if ($bytes !== false) {
            // Canonical literal. URL syntax pairs brackets with IPv6 and
            // bare text with IPv4; a mismatch is malformed, and the
            // transports would classify it differently than the gate —
            // refuse before the bit matrix can be consulted on ambiguous
            // bytes.
            if ((strlen($bytes) === 16) !== $bracketed) {
                return $bracketed
                    ? 'ml_pair_url host is bracketed but not valid IPv6 text'
                    : 'ml_pair_url host is a bracket-less IPv6 literal; IPv6 hosts must be bracketed';
            }
            $refused = self::refusedAddressReason($bytes, $allowPrivate);
            if ($refused === null) {
                return null;
            }
            // Honest guidance split: the opt-in admits the PRIVATE
            // families; reserved space is refused regardless, and the
            // message says so instead of offering a dead lever.
            if ($allowPrivate) {
                return 'ml_pair_url targets ' . $refused . ' space, which ml_pair_allow_private does not admit';
            }
            if (self::refusedAddressReason($bytes, true) !== null) {
                return 'ml_pair_url targets ' . $refused . ' space, refused by policy that even '
                    . 'ml_pair_allow_private (CLI: --ml-pair-allow-private) does not admit';
            }
            return 'ml_pair_url targets ' . $refused . ' space, refused by default policy; ' . self::optInGuidance();
        }
        if ($bracketed) {
            return 'ml_pair_url host is bracketed but not valid IPv6 text';
        }
        if (self::looksLikeNumericShorthand($host)) {
            return 'ml_pair_url host is an inet_aton numeric shorthand (hex/decimal/octal/partial dotted form), '
                . 'not a canonical IP literal; write the address in canonical dotted-quad form or use a hostname';
        }
        if (preg_match('/^[a-z0-9._\-]+$/', $host) !== 1) {
            return 'ml_pair_url host is not a valid hostname or IP literal';
        }
        // Bare name: admitted on shape. Resolution and the same bit
        // matrix run once at dial time (pairEndpointDecision), fail-
        // closed — admission never calls a resolver.
        return null;
    }

    /**
     * Dial door: the memoized endpoint decision this client will pin
     * and connect to. Returns the gateUrl-shaped decision (host, port,
     * checked addresses) or null to refuse every pair.
     *
     * @return null|array{host: string, port: int, addresses: list<string>}
     */
    public static function pairEndpointDecision(string $endpointUrl, bool $allowPrivate = false): ?array
    {
        if (self::pairUrlAdmissionError($endpointUrl, $allowPrivate) !== null) {
            return null;
        }
        $authority = self::pairAuthority($endpointUrl);
        if ($authority === null) {
            return null; // unreachable: admission accepted the shape
        }
        [$host, $port] = $authority;

        if (inet_pton($host) !== false) {
            // Canonical literal — nothing to resolve; the address the
            // matrix classified is exactly the address the transport
            // dials (engine-S19b pin property holds trivially).
            return ['host' => $host, 'port' => $port, 'addresses' => [$host]];
        }

        if (!$allowPrivate) {
            // Default policy: the shared fail-closed gate does the walk
            // (its per-name economics were never the once-076 problem —
            // ADMISSION was), and every record it returns is
            // re-classified through this door's matrix so the NAT64
            // class the shared gate predates cannot slip a name
            // through to a private address.
            $decision = MlClient::gateUrl($endpointUrl);
            if ($decision === null) {
                return null;
            }
            foreach ($decision['addresses'] as $address) {
                $bytes = inet_pton($address);
                if ($bytes === false || self::refusedAddressReason($bytes, false) !== null) {
                    return null;
                }
            }
            return $decision;
        }

        // Opt-in policy: the localhost deny-list no longer applies to
        // names either, so this door walks the resolver itself (same
        // two-backend discipline as gateUrl: dns_get_record for real
        // zones, gethostbynamel for hosts-file names), fail-closed on
        // zero records, and classifies every candidate under the
        // opted-in matrix.
        /** @var list<string> $candidates */
        $candidates = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                foreach (['ip', 'ipv6'] as $key) {
                    $address = $record[$key] ?? null;
                    if (is_string($address)) {
                        $candidates[] = $address;
                    }
                }
            }
        }
        if ($candidates === []) {
            $hostsFile = @gethostbynamel($host);
            if (is_array($hostsFile)) {
                $candidates = $hostsFile;
            }
        }
        if ($candidates === []) {
            return null;
        }
        foreach ($candidates as $address) {
            $bytes = inet_pton($address);
            if ($bytes === false || self::refusedAddressReason($bytes, true) !== null) {
                return null;
            }
        }
        return ['host' => $host, 'port' => $port, 'addresses' => $candidates];
    }

    /**
     * The refused-space bit matrix over an inet_pton wire form, as
     * policy sentences. Null means "admitted under this policy".
     * Distinct from {@see MlClient}'s private matrix on purpose:
     * the pair door must split always-refused reserved space from
     * the private families the opt-in admits, and must classify the
     * NAT64 well-known prefix (php-dup-finder-once-083).
     */
    private static function refusedAddressReason(string $bytes, bool $allowPrivate): ?string
    {
        return strlen($bytes) === 4
            ? self::refusedIPv4Reason($bytes, $allowPrivate)
            : self::refusedIPv6Reason($bytes, $allowPrivate);
    }

    /** @param string $bytes 4-byte inet_pton AF_INET wire form */
    private static function refusedIPv4Reason(string $bytes, bool $allowPrivate): ?string
    {
        $a0 = ord($bytes[0]);
        $a1 = ord($bytes[1]);
        if ($a0 === 0) {
            return '0.0.0.0/8 (this-network/unspecified)';
        }
        if ($a0 === 127) {
            return $allowPrivate ? null : 'loopback 127.0.0.0/8';
        }
        if ($a0 === 10 || ($a0 === 172 && $a1 >= 16 && $a1 <= 31) || ($a0 === 192 && $a1 === 168)) {
            return $allowPrivate ? null : 'RFC 1918 private';
        }
        if ($a0 === 100 && $a1 >= 64 && $a1 <= 127) {
            return $allowPrivate ? null : 'CGNAT 100.64.0.0/10';
        }
        if ($a0 === 169 && $a1 === 254) {
            return $allowPrivate ? null : 'link-local 169.254.0.0/16';
        }
        if ($a0 === 192 && $a1 === 0 && ord($bytes[2]) === 0) {
            return 'IETF protocol assignments 192.0.0.0/24';
        }
        if ($a0 === 198 && ($a1 === 18 || $a1 === 19)) {
            return 'benchmarking 198.18.0.0/15';
        }
        if ($a0 >= 224) {
            return 'multicast/reserved 224.0.0.0/4';
        }
        return null;
    }

    /** @param string $bytes 16-byte inet_pton AF_INET6 wire form */
    private static function refusedIPv6Reason(string $bytes, bool $allowPrivate): ?string
    {
        if ($bytes === inet_pton('::1')) {
            return $allowPrivate ? null : 'IPv6 loopback ::1';
        }
        $b0 = ord($bytes[0]);
        if ($b0 === 0xFF) {
            return 'IPv6 multicast ff00::/8';
        }
        if (($b0 & 0xFE) === 0xFC) {
            return $allowPrivate ? null : 'unique-local fc00::/7';
        }
        if ($b0 === 0xFE && (ord($bytes[1]) & 0xC0) === 0x80) {
            return $allowPrivate ? null : 'link-local fe80::/10';
        }
        if (substr($bytes, 0, 10) === str_repeat("\x00", 10)) {
            $b10 = ord($bytes[10]);
            $b11 = ord($bytes[11]);
            if ($b10 === 0x00 && $b11 === 0x00) {
                // Deprecated IPv4-compatible ::/96 (::, ::1 both land
                // in the embedded forms handled around this branch).
                $why = self::refusedIPv4Reason(substr($bytes, 12, 4), $allowPrivate);
                return $why === null ? null : 'IPv4-compatible ::/96 wrapping ' . $why;
            }
            if ($b10 === 0xFF && $b11 === 0xFF) {
                // IPv4-mapped ::ffff:0:0/96 — classify the embedded v4.
                $why = self::refusedIPv4Reason(substr($bytes, 12, 4), $allowPrivate);
                return $why === null ? null : 'IPv4-mapped ::ffff:0:0/96 wrapping ' . $why;
            }
        }
        // 64:ff9b::/96 on the wire is 00 64 ff 9b followed by eight
        // zero bytes (hextets are 16 bits — "64" means 0x0064).
        if (substr($bytes, 0, 4) === "\x00\x64\xff\x9b" && substr($bytes, 4, 8) === str_repeat("\x00", 8)) {
            // NAT64 well-known prefix (RFC 6052): 64:ff9b::7f00:1 is
            // 127.0.0.1 wearing IPv6 bytes — the php-dup-finder-once-083
            // bypass of the pre-existing gate. The embedded v4 decides.
            $why = self::refusedIPv4Reason(substr($bytes, 12, 4), $allowPrivate);
            return $why === null ? null : 'NAT64 64:ff9b::/96 wrapping ' . $why;
        }
        return null;
    }

    /**
     * Lower-cased, bracket-stripped authority + effective port of an
     * admission-shaped URL. Only call after pairUrlAdmissionError()
     * accepted the URL; null is a defensive never-branch.
     *
     * @return null|array{0: string, 1: int}
     */
    private static function pairAuthority(string $endpointUrl): ?array
    {
        $parts = parse_url($endpointUrl);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host   = (string)($parts['host'] ?? '');
        if ($host === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            return null;
        }
        $host = strtolower(trim($host, '[]'));
        if ($host === '') {
            return null;
        }
        return [$host, (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
    }

    private static function optInGuidance(): string
    {
        return 'set ml_pair_allow_private (CLI: --ml-pair-allow-private) to admit private-range '
            . 'sidecar addresses, or point ml_pair_url at a public address';
    }

    /**
     * Does $host consist purely of decimal/hex/octal components — i.e.
     * an inet_aton numeric form that failed canonical parsing? Same
     * shape test the shared gate applies (php-dup-finder-engine-012);
     * duplicated rather than widened visibility so neither class can
     * drift the other's refuse list.
     */
    private static function looksLikeNumericShorthand(string $host): bool
    {
        return preg_match('/^(?:[0-9]+|0x[0-9a-f]+)(?:\.(?:[0-9]+|0x[0-9a-f]+))*$/', $host) === 1;
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
     * @internal test seam (engine-S21 counting): real dial decisions
     *           computed so far (since once-S54 the counting door is
     *           pairEndpointDecision(); a canonical literal mints the
     *           count without a resolver walk); must stay 1 however
     *           many pairs score() and scoreBatch() dial.
     */
    private int $urlGateCalls = 0;

    /** @var \CurlHandle|null handle owned by the pid in $curlHandlePid */
    private ?\CurlHandle $curlHandle = null;

    private int $curlHandlePid = 0;

    /**
     * The memoized gate decision for this instance's authority.
     *
     * host/port/addresses derive from the URL string alone (the path
     * never enters the decision), so gating the representative
     * `/score-pair` URL decides identically for `/score-batch` — one
     * resolver walk per client, verdict cached (engine-S21 economics).
     * once-S54: the walk goes through this class's own pair door
     * ({@see self::pairEndpointDecision()}), which keeps the shared
     * gate's default-policy behavior for names, adds the NAT64 class,
     * and honors the instance's `allowPrivate` opt-in.
     *
     * @return null|array{host: string, port: int, addresses: list<string>}
     */
    private function endpointDecision(): ?array
    {
        if (!$this->gateResolved) {
            $this->gateResolved = true;
            $this->urlGateCalls++;
            $this->gateDecision = self::pairEndpointDecision(
                rtrim($this->baseUrl, '/') . '/score-pair',
                $this->allowPrivate,
            );
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
     * once-S90 (php-dup-finder-once-113) closes the finding's
     * asymmetry leg: this sibling of parseBatchVerdicts() must refuse
     * exactly the values that one refuses — a junk number can never
     * be laundered by one endpoint and fenced by the other. The
     * symmetry is permanently pinned: MlPairClientTest drives a fuzz
     * list of refused verdict values through BOTH endpoints in BOTH
     * the named and the positional spelling, and a token census
     * proves the shared fence is this class's ONLY verdict-domain
     * logic (zero float casts, zero floatval/settype anywhere).
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
     * shared closed-[0,1] fence {@see MlClient::coerceUnitFloat()} —
     * the same fence score()'s parseVerdict() consults, one coerce
     * fn for both siblings (php-dup-finder-once-113, once-S90). A
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
     * refusal, or a response larger than MlClient::MAX_RESPONSE_BYTES
     * — and, since once-S92 (php-dup-finder-once-115), on any
     * transfer that misses the instance's total deadline or whose
     * body does not answer its declared Content-Length: a late or
     * cut-short reply refuses whole instead of decoding into a
     * half-truth. Both seams thread the SAME MlClient::MAX_RESPONSE_
     * BYTES ceiling; this class owns no second cap constant (pinned
     * by token census in MlPairClientTest).
     * The pinning and cap policy is shared with MlClient (engine-S19b)
     * and since once-S89 the transport MECHANICS are too — both seam
     * methods below are thin delegations onto Util\Http, this client
     * contributing its fork-guarded reused handle (engine-S21).
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
     * curl seam: pin-computation policy via MlClient (engine-S19b),
     * transport mechanics via Util\Http, connection economics via the
     * one shared handle below (engine-S21 keep-alive). The total
     * deadline is native on this leg: CURLOPT_TIMEOUT bounds the
     * whole transfer in-call (once-S92 wording pin).
     *
     * @param array{host: string, port: int, addresses: list<string>} $decision
     */
    private function postJsonCurl(string $url, string $body, array $decision): ?string
    {
        $ch = $this->sharedCurlHandle();
        if ($ch === null) {
            return null;
        }
        return Http::curlPost($url, $body, MlClient::curlPinEntries($decision), $ch, $this->timeoutSec, MlClient::MAX_RESPONSE_BYTES);
    }

    /**
     * stream seam: rewrite policy via MlClient::pinnedStreamRequest(),
     * transport mechanics — including the PHP-version split and the
     * LegacyStreamPost isolation that keeps the deprecated magic-
     * variable COMPILED read out of every file loaded on >= 8.5 (the
     * CI-85 leg-5 lesson, and the divergence php-dup-finder-once-112
     * caught between the twins) — live once in Util\Http::streamPost().
     * That is also where the total-deadline conversion of this leg's
     * historically per-read-inactivity timeout lives (once-S92,
     * php-dup-finder-once-115; see the streamPost docblock for the
     * per-arm granularities).
     *
     * @param array{host: string, port: int, addresses: list<string>} $decision
     */
    private function postJsonStream(string $url, string $body, array $decision): ?string
    {
        return Http::streamPost(MlClient::pinnedStreamRequest($url, $decision), $body, $this->timeoutSec, MlClient::MAX_RESPONSE_BYTES);
    }

    /**
     * @internal retained as the thin delegate MlPairClientTest's
     *           reflection pin (engine era, parseStatusCode matrix)
     *           reaches; the parsing lives once in Util\Http
     *           (once-S89).
     *
     * @param array<string> $headers
     * @phpstan-ignore method.unused (deliberately retained reflection seam, reached only from tests)
     */
    private function parseStatusCode(array $headers): int
    {
        return Http::parseStatusCode($headers);
    }
}
