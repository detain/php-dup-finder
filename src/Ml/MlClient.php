<?php
declare(strict_types=1);

namespace Phpdup\Ml;

use JsonException;
use Phpdup\Clustering\Cluster;

/**
 * HTTP client for an external ML scoring service.
 *
 * The service is intentionally out-of-process — phpdup's hot path
 * stays PHP; the model lives in Python (or any sidecar) and is
 * accessed over HTTP. Phpdup ships only the contract:
 *
 *   POST /score
 *   {
 *     "cluster_id": "F6173f8e7fbff89aa",
 *     "similarity": 0.93,
 *     "members": <int>,
 *     "holes": <int>,
 *     "pattern_tags": ["sql-builder", …]
 *   }
 *
 *   →  { "safety": 0.71, "anomaly": 0.12 }
 *
 * Both numbers are parsed into the closed unit interval before they
 * can influence anything (engine-S20, php-dup-finder-engine-013): a
 * sidecar answering with INF, NaN, booleans, arrays or out-of-domain
 * values is treated exactly like a malformed response — a null
 * verdict and a local fallback — never a laundered score.
 *
 * Falls back gracefully when the server is unreachable: returns null
 * so callers fall back to {@see \Phpdup\Reporting\SafetyScorer}.
 *
 * Security
 * --------
 * The base URL is config-supplied, so it is treated as
 * remote-controlled. {@see MlClient::isAllowedUrl()} enforces:
 *
 * - http(s) scheme only (no `file://`, `gopher://`, `ftp://`, …);
 * - a well-formed URL with an explicit host;
 * - the host is either a CANONICAL IP literal (inet_pton syntax only —
 *   the `inet_aton` shorthands `0x7f000001`, `2130706433`, `127.1`,
 *   `0177.0.0.1` are refused, php-dup-finder-engine-012) or a name
 *   that resolves to at least one A/AAAA record — unresolvable names
 *   are refused fail-closed;
 * - every candidate address (literal or resolved) clears the refused-
 *   address-space bit checks listed on {@see MlClient::isAllowedUrl()}.
 *
 * Transport legs (engine-S19b, closing engine-012 hole c + engine-015):
 * the addresses checked here are the addresses connected to. The curl
 * transport pins them via CURLOPT_RESOLVE; the stream transport — whose
 * wrapper has no resolve-pinning option — rewrites a named host to the
 * first checked address, carries the original authority in an explicit
 * Host header, and keeps https certificate identity via ssl.peer_name.
 * Response bodies are capped at MAX_RESPONSE_BYTES on both transports
 * (curl write-callback abort; stream bounded read). Callers that need a
 * tighter policy (e.g. only specific hostnames) can still prefilter the
 * URL before constructing the client.
 *
 * Per-instance economics (engine-S21, php-dup-finder-engine-032): the
 * base URL is fixed at construction, so the gate decision (host, port,
 * checked addresses — the path never enters it) is computed at most
 * ONCE per client and reused, refusals included. The old per-call
 * re-check walked the resolver on every score(); a sidecar whose DNS
 * moves mid-run now stays pinned to the first checked address until a
 * fresh client is constructed — under the S19b pin the per-call
 * re-check could never have dialed an unchecked record anyway, so the
 * only thing the memo gives up is following a legitimate relocation.
 * (The pair client additionally reuses one curl handle for keep-alive;
 * the cluster path dials once per cluster and gains nothing from it.)
 */
final class MlClient
{
    /**
     * Hard bound on response-body bytes the transports will buffer
     * (php-dup-finder-engine-015: a hostile sidecar behind an allowed
     * URL used to grow per-worker RSS without bound — measured +129 MB
     * from a 64 MB body). Public so MlPairClient shares the same cap
     * and the two clients can never drift it.
     */
    public const MAX_RESPONSE_BYTES = 1_048_576;

    /**
     * @param string $baseUrl    HTTP(S) URL prefix of the scoring service. Empty = disabled.
     * @param int    $timeoutSec Total read timeout passed to the HTTP context.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSec = 5,
    ) {
    }

    /**
     * One gate decision per instance (engine-S21, removing the per-call
     * isAllowedUrl re-check of php-dup-finder-engine-032). `false` until
     * the first score() resolves it lazily — a client that never scores
     * performs no resolver work at all.
     */
    private bool $gateResolved = false;

    /** @var null|array{host: string, port: int, addresses: list<string>} */
    private ?array $gateDecision = null;

    /**
     * @internal test seam (engine-S21 counting): real gateUrl() runs so
     *           far; must stay 1 however many clusters score() dials.
     */
    private int $urlGateCalls = 0;

    /**
     * The memoized gate decision for this instance's single endpoint.
     *
     * @return null|array{host: string, port: int, addresses: list<string>}
     */
    private function endpointDecision(): ?array
    {
        if (!$this->gateResolved) {
            $this->gateResolved = true;
            $this->urlGateCalls++;
            $this->gateDecision = self::gateUrl(rtrim($this->baseUrl, '/') . '/score');
        }
        return $this->gateDecision;
    }

    /**
     * Parse one remote verdict number into a trusted score in [0, 1].
     *
     * engine-S20 (php-dup-finder-engine-013): the ML transports used to
     * launder every response field through a bare `(float)` cast — no
     * domain check of any kind. `{"similarity":1e999}` decodes to INF
     * and sails past every `>= threshold` gate (FUZZ-03 watched an
     * INF-scored pair emit as a cluster; the report layer's
     * `min(1.0, …)` masked it in JSON while the DECISION stayed
     * attacker-made). `(float)` on an array yields 1.0/0.0, on
     * "garbage" 0.0 — wrong types arriving as plausible scores.
     *
     * The wire contract is a probability-like float in the CLOSED unit
     * interval. Anything else is malformed and yields null so callers
     * fall back exactly as they do for an absent field: non-numeric
     * values (bool, array, null, "NaN", "garbage"), non-finite floats
     * (INF/NAN — `is_numeric` accepts those as floats, so the finite
     * check is load-bearing), and numbers outside [0, 1]. Numeric
     * JSON strings ("0.9") parse: `is_numeric` proves the shape before
     * the cast, matching the finding's prescribed domain test exactly.
     *
     * @internal Shared verdict-domain policy for both Ml clients
     *           (engine-S20) so score()/scoreBatch()/cluster scoring
     *           parse remote numbers through ONE fence and cannot drift.
     */
    public static function coerceUnitFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $float = (float)$value;
        if (!is_finite($float) || $float < 0.0 || $float > 1.0) {
            return null;
        }
        return $float;
    }

    /**
     * Score a cluster against the remote ML service.
     *
     * @return array{safety: float, anomaly: float}|null Null when the
     *         service is unreachable, returns malformed data, or the
     *         configured base URL fails validation.
     */
    public function score(Cluster $cluster): ?array
    {
        if ($this->baseUrl === '') {
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . '/score';
        $decision = $this->endpointDecision();
        if ($decision === null) {
            return null;
        }

        $payload = [
            'cluster_id'   => $cluster->id,
            'similarity'   => $cluster->similarity,
            'members'      => $cluster->size(),
            'holes'        => count($cluster->holes),
            'pattern_tags' => $cluster->patternTags,
        ];
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $resp = $this->postJson($url, $body, $decision);
        if ($resp === null) {
            return null;
        }
        return $this->parseSafetyVerdict($resp);
    }

    /**
     * Decode a /score verdict body into a trusted safety pair.
     *
     * engine-S20 (php-dup-finder-engine-013): `safety` and `anomaly`
     * arrive through the shared closed-[0,1] domain fence {@see
     * MlClient::coerceUnitFloat()}. Absent, non-numeric, non-finite or
     * out-of-domain fields void the whole verdict (null) so the caller
     * falls back to the local SafetyScorer instead of consuming a
     * laundered number.
     *
     * @return array{safety: float, anomaly: float}|null
     */
    private function parseSafetyVerdict(string $resp): ?array
    {
        try {
            $decoded = json_decode($resp, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $safety  = self::coerceUnitFloat($decoded['safety'] ?? null);
        $anomaly = self::coerceUnitFloat($decoded['anomaly'] ?? null);
        if ($safety === null || $anomaly === null) {
            return null;
        }
        return ['safety' => $safety, 'anomaly' => $anomaly];
    }

    /**
     * Is `$url` something we are willing to fetch?
     *
     * Public so CLI admission (`Config`, `Command`, `ConfigLoader`) and
     * test code can validate URLs without exercising the full HTTP path.
     *
     * SSRF policy (rebuilt per php-dup-finder-engine-012)
     * ----------------------------------------------------
     * - http(s) scheme only (no `file://`, `gopher://`, `ftp://`, …)
     * - well-formed URL with an explicit host
     * - `localhost` and `*.localhost` (RFC 6761 special-use) refused
     *   without resolution
     * - canonical literals only: `inet_pton` accepts strict dotted-quad
     *   and RFC 5952-style IPv6 text, so every `inet_aton` shorthand
     *   (hex `0x7f000001`, decimal `2130706433`, abbreviated `127.1`,
     *   octal `0177.0.0.1`, partial forms like `192.168.1`) is refused
     *   as an invalid literal BEFORE name resolution — the gate and the
     *   transports must never disagree about what the host bytes mean
     * - bracketed host text must parse as IPv6; an IPv4 literal inside
     *   brackets is invalid URL syntax and refused
     * - a bare name must resolve (dns_get_record A+AAAA, falling back to
     *   gethostbynamel for hosts-file names) to at least one record;
     *   zero records REFUSE the URL (fail-closed flip: the old gate
     *   allowed names whose DNS was down at check time)
     * - every candidate address is bit-checked over its inet_pton wire
     *   form against the refused space — IPv4: 0.0.0.0/8, RFC 1918,
     *   CGNAT 100.64.0.0/10, 127.0.0.0/8, 169.254.0.0/16, 192.0.0.0/24,
     *   198.18.0.0/15, 224.0.0.0/4 and 240.0.0.0/4 (multicast,
     *   reserved, broadcast); IPv6: ::/128 (unspecified), ::1/128
     *   (loopback), fc00::/7 (ULA), fe80::/10 (link-local), ff00::/8
     *   (multicast), and the IPv4-compatible ::/96 plus IPv4-mapped
     *   ::ffff:0:0/96 spaces recurse into the IPv4 checks — so
     *   `::ffff:127.0.0.1`, `0:0:0:0:0:0:0:1` and `fc00::/7` metadata
     *   targets all refuse; an address string that inet_pton cannot
     *   parse is refused too (cannot classify → cannot connect)
     *
     * The transport legs of this policy — connect-time pinning of the
     * checked addresses (engine-012 hole c) and the response size caps
     * (engine-015) — are enforced by the consumers of {@see
     * MlClient::gateUrl()}: curlPinEntries() / pinnedStreamRequest()
     * feed the two postJson transports (engine-S19b).
     */
    public static function isAllowedUrl(string $url): bool
    {
        return self::gateUrl($url) !== null;
    }

    /**
     * The full gate decision: not just whether $url passes, but what
     * exactly was verified. The transports in both Ml clients pin the
     * addresses returned here so the connection can never be made to a
     * record the bit checks did not classify (DNS-rebinding close-out,
     * engine-S19b).
     *
     * Policy is identical to {@see MlClient::isAllowedUrl()} (which is
     * this method's boolean face); see its docblock for the refuse list.
     *
     * @return null|array{host: string, port: int, addresses: list<string>}
     *         Null when the URL is refused. Otherwise the lower-cased,
     *         bracket-stripped authority host, the effective port
     *         (explicit, else the scheme default), and every address
     *         the gate bit-checked — the canonical literal itself for
     *         IP-literal hosts, all resolver records for names.
     */
    public static function gateUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            return null;
        }
        $bracketed = str_starts_with($host, '[');
        if ($bracketed) {
            // Strip IPv6 brackets — parse_url keeps them (e.g. '[::1]').
            $host = trim($host, '[]');
        }
        if ($host === '') {
            return null;
        }
        $host = strtolower($host);

        // RFC 6761 special-use names: refuse without touching the resolver.
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }

        $bytes = inet_pton($host);
        if ($bytes !== false) {
            // Canonical literal. URL syntax pairs brackets with IPv6 and
            // bare text with IPv4; a mismatch is a malformed literal.
            if ((strlen($bytes) === 16) !== $bracketed) {
                return null;
            }
            if (self::isBlockedBytes($bytes)) {
                return null;
            }
            return ['host' => $host, 'port' => $port, 'addresses' => [$host]];
        }
        if ($bracketed) {
            return null; // bracketed text that is not valid IPv6
        }
        if (self::looksLikeNumericShorthand($host)) {
            // inet_aton family (hex/decimal/octal/partial dotted forms).
            // Left as a "name" it would fail resolution on some boxes and
            // be numeric-parsed by the transport on others — the exact
            // gate-vs-transport divergence engine-012's PoC exploited.
            return null;
        }

        // Bare name: fail closed on zero records, refuse on any refused
        // record among everything the resolvers returned.
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
            // Resolver backends differ on /etc/hosts visibility through
            // dns_get_record; gethostbynamel covers hosts-file names.
            // Everything it yields is still bit-checked below.
            $hostsFile = @gethostbynamel($host);
            if (is_array($hostsFile)) {
                $candidates = $hostsFile;
            }
        }
        if ($candidates === []) {
            return null;
        }
        foreach ($candidates as $address) {
            if (self::isBlockedAddress($address)) {
                return null;
            }
        }
        return ['host' => $host, 'port' => $port, 'addresses' => $candidates];
    }

    /**
     * Does $host consist purely of decimal/hex components — i.e. an
     * inet_aton numeric form that failed canonical parsing? Legitimate
     * hostnames that merely contain hex-ish labels ("db1", "cafe") are
     * NOT matched unless every component is digits or a 0x-prefixed
     * number.
     */
    private static function looksLikeNumericShorthand(string $host): bool
    {
        return preg_match('/^(?:[0-9]+|0x[0-9a-f]+)(?:\.(?:[0-9]+|0x[0-9a-f]+))*$/', $host) === 1;
    }

    /**
     * Classify a textual IP address against the refused address space.
     *
     * Unparsable strings are refused: an address we cannot classify is
     * an address we must not connect to (fail-closed).
     */
    private static function isBlockedAddress(string $address): bool
    {
        $bytes = inet_pton($address);
        if ($bytes === false) {
            return true;
        }
        return self::isBlockedBytes($bytes);
    }

    /**
     * Bit checks over the inet_pton wire form (4-byte IPv4 / 16-byte
     * IPv6), replacing the old filter_var(NO_PRIV_RANGE|NO_RES_RANGE)
     * posture which missed CGNAT, ULA, fe80::/10 and the IPv4 embedded
     * in ::ffff:/96 mapped addresses (engine-012 hole b).
     */
    private static function isBlockedBytes(string $bytes): bool
    {
        if (strlen($bytes) === 4) {
            return self::isBlockedIPv4Bytes($bytes);
        }
        $b0 = ord($bytes[0]);
        if ($b0 === 0xFF) {
            return true; // ff00::/8 multicast
        }
        if (($b0 & 0xFE) === 0xFC) {
            return true; // fc00::/7 unique-local
        }
        if ($b0 === 0xFE && (ord($bytes[1]) & 0xC0) === 0x80) {
            return true; // fe80::/10 link-local
        }
        if (substr($bytes, 0, 10) === str_repeat("\x00", 10)) {
            $b10 = ord($bytes[10]);
            $b11 = ord($bytes[11]);
            if ($b10 === 0x00 && $b11 === 0x00) {
                // Deprecated IPv4-compatible ::/96 — the v4 address hides
                // in the last four bytes (:: and ::1 both land here).
                return self::isBlockedIPv4Bytes(substr($bytes, 12, 4));
            }
            if ($b10 === 0xFF && $b11 === 0xFF) {
                // IPv4-mapped ::ffff:0:0/96 — classify the embedded v4.
                return self::isBlockedIPv4Bytes(substr($bytes, 12, 4));
            }
        }
        return false;
    }

    private static function isBlockedIPv4Bytes(string $bytes): bool
    {
        $a0 = ord($bytes[0]);
        $a1 = ord($bytes[1]);
        if ($a0 === 0) {
            return true; // 0.0.0.0/8 "this host on this network"
        }
        if ($a0 === 10) {
            return true; // RFC 1918
        }
        if ($a0 === 100 && $a1 >= 64 && $a1 <= 127) {
            return true; // CGNAT RFC 6598
        }
        if ($a0 === 127) {
            return true; // loopback
        }
        if ($a0 === 169 && $a1 === 254) {
            return true; // link-local (cloud metadata endpoints)
        }
        if ($a0 === 172 && $a1 >= 16 && $a1 <= 31) {
            return true; // RFC 1918
        }
        if ($a0 === 192 && $a1 === 0 && ord($bytes[2]) === 0) {
            return true; // 192.0.0.0/24 IETF protocol assignments
        }
        if ($a0 === 192 && $a1 === 168) {
            return true; // RFC 1918
        }
        if ($a0 === 198 && ($a1 === 18 || $a1 === 19)) {
            return true; // 198.18.0.0/15 benchmarking
        }
        if ($a0 >= 224) {
            return true; // 224.0.0.0/4 multicast, 240.0.0.0/4 reserved + broadcast
        }
        return false;
    }

    /**
     * CURLOPT_RESOLVE pin entries for the gate-checked addresses
     * (engine-012 hole c: connect-time resolution must never disagree
     * with check-time resolution). An empty list means "nothing to
     * pin" — a canonical literal host, which curl dials directly
     * without the resolver. Null is a refusal: a decision shape the
     * gate could not have produced (no checked addresses at all) is
     * fail-closed, never handed to a transport that would resolve the
     * name on its own.
     *
     * @internal Shared transport policy for both Ml clients
     *           (engine-S19b) so the pinning rules cannot drift.
     * @param array{host: string, port: int, addresses: list<string>} $decision
     * @return list<string>|null
     */
    public static function curlPinEntries(array $decision): ?array
    {
        if ($decision['addresses'] === []) {
            return null;
        }
        $host = $decision['host'];
        if (self::isHeaderUnsafeHost($host)) {
            return null;
        }
        if (inet_pton($host) !== false) {
            return [];
        }
        return [$host . ':' . $decision['port'] . ':' . implode(',', $decision['addresses'])];
    }

    /**
     * Stream-transport pinning. The PHP http wrapper has no
     * CURLOPT_RESOLVE analogue, so a named host is rewritten to its
     * first gate-checked address (the resolver is never consulted for
     * it), with the original authority kept in an explicit Host header
     * (RFC 9112 §3.2); an https request keeps certificate identity
     * through ssl.peer_name, so the pinned dial cannot mask a
     * wrong-host certificate. Canonical-literal hosts pass through
     * byte-identically (the wrapper numeric-parses them; no lookup).
     * Null is a refusal — same fail-closed rule as curlPinEntries().
     *
     * @internal Shared transport policy for both Ml clients
     *           (engine-S19b).
     * @param array{host: string, port: int, addresses: list<string>} $decision
     * @return null|array{url: string, header: string, peer_name: string|null}
     */
    public static function pinnedStreamRequest(string $url, array $decision): ?array
    {
        if ($decision['addresses'] === []) {
            return null;
        }
        $host = $decision['host'];
        if (self::isHeaderUnsafeHost($host)) {
            return null;
        }
        if (inet_pton($host) !== false) {
            return [
                'url'       => $url,
                'header'    => "Content-Type: application/json\r\n",
                'peer_name' => null,
            ];
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
        $target = $decision['addresses'][0];
        if (str_contains($target, ':')) {
            $target = '[' . $target . ']';
        }
        $userinfo = '';
        if (isset($parts['user'])) {
            $userinfo = $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }
        $path  = (string)($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return [
            'url'       => $scheme . '://' . $userinfo . $target . ':' . $decision['port'] . $path . $query,
            'header'    => "Content-Type: application/json\r\nHost: $host:" . $decision['port'] . "\r\n",
            'peer_name' => $scheme === 'https' ? $host : null,
        ];
    }

    /**
     * Defense-in-depth: gateUrl() only ever emits label-safe hosts, but
     * a host text is about to be placed into a request header — refuse
     * anything with control or separator bytes rather than trust the
     * call site.
     */
    private static function isHeaderUnsafeHost(string $host): bool
    {
        return $host === '' || preg_match('/^[a-z0-9._\-]+$/', $host) !== 1;
    }

    /**
     * POST `$body` as JSON to `$url` and return the raw response body.
     *
     * Uses ext-curl when available (richer error reporting and easier
     * to harden) and falls back to streams when not. Returns null on
     * any transport-level failure, non-2xx HTTP response, pinning
     * refusal, or a response larger than MAX_RESPONSE_BYTES.
     *
     * @param array{host: string, port: int, addresses: list<string>} $decision
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
        $pins = self::curlPinEntries($decision);
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
            // Refuse silly redirects to file:// / gopher:// / ftp://
            // — only http(s) on the original request is allowed.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION  => function (\CurlHandle $handle, string $chunk) use (&$buffer, &$overflowed): int {
                if (strlen($chunk) > self::MAX_RESPONSE_BYTES - strlen($buffer)) {
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
        $pin = self::pinnedStreamRequest($url, $decision);
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
        $resp = stream_get_contents($fh, self::MAX_RESPONSE_BYTES + 1);
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
        if (strlen($resp) > self::MAX_RESPONSE_BYTES) {
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
