<?php
declare(strict_types=1);

namespace Acme\Sso\Google;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class GoogleSsoStart
{
    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        $state = bin2hex(random_bytes(16));
        $params = [
            'client_id'     => '843271-acme-portal.apps.googleusercontent.com',
            'redirect_uri'  => 'https://app.acme.io/sso/callback',
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'prompt'        => 'select_account',
            'access_type'   => 'offline',
        ];

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

        $resp = new Response(302);
        $resp = $resp
            ->withHeader('Location', $url)
            ->withHeader('Set-Cookie', sprintf(
                'sso_state=%s; HttpOnly; Secure; Path=/; SameSite=Lax; Max-Age=600',
                $state
            ));

        return $resp;
    }
}
