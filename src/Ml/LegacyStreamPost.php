<?php
declare(strict_types=1);

namespace Phpdup\Ml;

/**
 * Legacy stream transport holding the ONLY compiled mention of the
 * $http_response_header magic variable left in src/Ml.
 *
 * PHP 8.5 deprecates that magic variable (replaced by
 * http_get_last_response_headers()) — and the deprecation flags the
 * compiled read itself, so any literal in a file loaded on 8.5 fires even
 * when its branch never executes. A variable-variable cannot substitute:
 * the stream wrapper materialises the symbol only for direct compiled CV
 * reads in the scope that performed the fopen. This class therefore keeps
 * the fopen and the magic read in ONE pre-8.5-only scope, and — critically
 * — is referenced ONLY from MlPairClient's function_exists() false
 * branch. PHP resolves that class reference lazily at execution time, so
 * on PHP >= 8.5 this file is never compiled and the deprecation stays
 * silent. DO NOT reference LegacyStreamPost anywhere reachable on 8.5
 * (tests included); its pin is MlPairClientTest's real-transport tests,
 * which run the pre-8.5 path exactly as MlPairClient used to.
 */
final class LegacyStreamPost
{
    /**
     * @param array<string, mixed> $ctxOpts stream context options for the POST
     * @param int                  $maxReadBytes hard read ceiling (stream_get_contents maxlen)
     *
     * @return array{0: ?string, 1: list<string>} body (null on transport failure) and response headers
     */
    public static function post(string $url, array $ctxOpts, int $maxReadBytes): array
    {
        $ctx = stream_context_create($ctxOpts);
        $fh  = @fopen($url, 'r', false, $ctx);
        if ($fh === false) {
            return [null, []];
        }
        $resp = stream_get_contents($fh, $maxReadBytes);
        $headers = $http_response_header;
        fclose($fh);
        if ($resp === false) {
            return [null, $headers];
        }
        return [$resp, $headers];
    }
}
