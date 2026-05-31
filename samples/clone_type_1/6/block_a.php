<?php
declare(strict_types=1);

namespace Acme\Http\Middleware\Api;

final class ApiAuthMiddleware
{
    /**
     * Assert that decoded JWT claims satisfy the API gate.
     *
     * @param array<string,mixed> $claims decoded JWT payload
     */
    public function assertClaims(array $claims): bool
    {
        if (!isset($claims['exp']) || !is_int($claims['exp'])) {
            return false;
        }
        if ($claims['exp'] < time()) {
            return false;
        }
        if (!isset($claims['iss']) || $claims['iss'] !== 'acme-issuer') {
            return false;
        }
        if (!isset($claims['aud']) || $claims['aud'] !== 'acme-api') {
            return false;
        }
        $scopes = $claims['scopes'] ?? [];
        if (!is_array($scopes) || count($scopes) === 0) {
            return false;
        }
        return in_array('read', $scopes, true);
    }

    public function handle(): void
    {
        // decode token and call assertClaims()
    }
}
