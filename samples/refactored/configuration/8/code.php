<?php
declare(strict_types=1);

namespace Acme\Sso;

final class OAuthClientConfig
{
    public const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const CLIENT_ID    = '843271-acme-portal.apps.googleusercontent.com';
    public const REDIRECT_URI = 'https://app.acme.io/sso/callback';
    public const SCOPE        = 'openid email profile';

    /**
     * @param array<string, string> $extra
     */
    public static function buildAuthorizeUrl(string $state, array $extra = []): string
    {
        $params = array_replace([
            'client_id'     => self::CLIENT_ID,
            'redirect_uri'  => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'state'         => $state,
            'access_type'   => 'offline',
        ], $extra);

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public static function stateCookie(string $state): string
    {
        return sprintf(
            'sso_state=%s; HttpOnly; Secure; Path=/; SameSite=Lax; Max-Age=600',
            $state
        );
    }
}

// Usage:
// $url = OAuthClientConfig::buildAuthorizeUrl($state, ['prompt' => 'select_account']);
// $resp->withHeader('Location', $url)->withHeader('Set-Cookie', OAuthClientConfig::stateCookie($state));
