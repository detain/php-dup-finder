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
 * Known remaining limit, disclosed honestly: the gate resolves the
 * name at check time and the transport resolves again at connect
 * time; without CURLOPT_RESOLVE/bindto pinning (sibling hardening of
 * this finding) a DNS-rebinding zone can still pass the gate and
 * connect elsewhere. Callers that need a tighter policy (e.g. only
 * specific hostnames) can prefilter the URL before constructing the
 * client.
 */
final class MlClient
{
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
        if (!self::isAllowedUrl($url)) {
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

        $resp = $this->postJson($url, $body);
        if ($resp === null) {
            return null;
        }
        try {
            $decoded = json_decode($resp, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $safety  = isset($decoded['safety'])  ? (float)$decoded['safety']  : null;
        $anomaly = isset($decoded['anomaly']) ? (float)$decoded['anomaly'] : null;
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
     * Not yet closed by this gate (sibling hardening owns the transport
     * legs): connect-time IP pinning against DNS rebinding, and response
     * size caps on the two postJson transports.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }
        $bracketed = str_starts_with($host, '[');
        if ($bracketed) {
            // Strip IPv6 brackets — parse_url keeps them (e.g. '[::1]').
            $host = trim($host, '[]');
        }
        if ($host === '') {
            return false;
        }
        $host = strtolower($host);

        // RFC 6761 special-use names: refuse without touching the resolver.
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        $bytes = inet_pton($host);
        if ($bytes !== false) {
            // Canonical literal. URL syntax pairs brackets with IPv6 and
            // bare text with IPv4; a mismatch is a malformed literal.
            if ((strlen($bytes) === 16) !== $bracketed) {
                return false;
            }
            return !self::isBlockedBytes($bytes);
        }
        if ($bracketed) {
            return false; // bracketed text that is not valid IPv6
        }
        if (self::looksLikeNumericShorthand($host)) {
            // inet_aton family (hex/decimal/octal/partial dotted forms).
            // Left as a "name" it would fail resolution on some boxes and
            // be numeric-parsed by the transport on others — the exact
            // gate-vs-transport divergence engine-012's PoC exploited.
            return false;
        }

        // Bare name: fail closed on zero records, refuse on any refused
        // record among everything the resolvers returned.
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
            return false;
        }
        foreach ($candidates as $address) {
            if (self::isBlockedAddress($address)) {
                return false;
            }
        }
        return true;
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
     * POST `$body` as JSON to `$url` and return the raw response body.
     *
     * Uses ext-curl when available (richer error reporting and easier
     * to harden) and falls back to streams when not. Returns null on
     * any transport-level failure or non-2xx HTTP response.
     */
    private function postJson(string $url, string $body): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            if ($ch === false) {
                return null;
            }
            // CI-85: no curl_close — deprecated in PHP 8.5 and a no-op
            // since 8.0 (the handle closes on GC), which is why the
            // try/finally that carried only that call is gone too.
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeoutSec,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSec,
                // Refuse silly redirects to file:// / gopher:// / ftp://
                // — only http(s) on the original request is allowed.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode < 200 || $httpCode >= 300) {
                return null;
            }
            if (!is_string($resp)) {
                return null;
            }
            return $resp;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $body,
                'timeout'       => $this->timeoutSec,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp !== false) {
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
        }
        return $resp === false ? null : $resp;
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
