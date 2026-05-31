<?php
declare(strict_types=1);

namespace Acme\Http\Middleware\Support;

final class JwtClaimAsserter
{
    /**
     * Assert that decoded JWT claims satisfy the common gate.
     *
     * @param array<string,mixed> $claims
     */
    public static function assert(array $claims): bool
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
}

// Each middleware now calls JwtClaimAsserter::assert($claims).
