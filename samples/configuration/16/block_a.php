<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

final class SessionAuthMiddleware
{
    private const SESSION_LIFETIME = 120;
    private const SESSION_EXTEND_ON_ACTIVITY = true;
    private const SESSION_REGENERATE_INTERVAL = 300;
    private const SESSION_SECURE_COOKIE = true;
    private const SESSION_HTTP_ONLY = true;
    private const SESSION_SAME_SITE = 'lax';
    private const SESSION_DOMAIN = null;
    private const SESSION_PATH = '/';
    private const SESSION_ACTIVITY_UPDATE = true;

    private int $lastActivity;
    private bool $sessionRegenerated = false;

    public function __construct()
    {
        $this->lastActivity = time();
    }

    public function handle(Request $request, \Closure $next): Response
    {
        if (!$request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();

        if (!$session->has('last_activity')) {
            $session->put('last_activity', time());
        }

        $this->lastActivity = $session->get('last_activity');

        if (self::SESSION_EXTEND_ON_ACTIVITY) {
            $timeSinceLastActivity = time() - $this->lastActivity;

            if ($timeSinceLastActivity >= self::SESSION_LIFETIME) {
                $session->flush();
                $session->invalidate();
                $session->regenerate(true);

                return redirect('/login')->with('error', 'Session expired');
            }

            if (self::SESSION_ACTIVITY_UPDATE) {
                $session->put('last_activity', time());
            }
        }

        if (!$this->sessionRegenerated &&
            (time() - $session->get('_session_created', 0)) > self::SESSION_REGENERATE_INTERVAL) {
            $session->regenerate(true);
            $session->put('_session_created', time());
            $this->sessionRegenerated = true;
        }

        if ($session->has('user_id')) {
            $userId = $session->get('user_id');

            if (!$session->has('_session_created')) {
                $session->put('_session_created', time());
            }

            $session->put('user_id', $userId);
        }

        $response = $next($request);

        $this->configureSessionCookie($response);

        return $response;
    }

    private function configureSessionCookie(Response $response): void
    {
        if (headers_sent()) {
            return;
        }

        $cookieOptions = [
            'expires' => time() + self::SESSION_LIFETIME,
            'path' => self::SESSION_PATH,
            'domain' => self::SESSION_DOMAIN,
            'secure' => self::SESSION_SECURE_COOKIE,
            'httponly' => self::SESSION_HTTP_ONLY,
            'samesite' => self::SESSION_SAME_SITE,
        ];

        $response->headers->setCookie(
            cookie('laravel_session', session()->getId(), $cookieOptions)
        );
    }

    public function getSessionLifetime(): int
    {
        return self::SESSION_LIFETIME;
    }

    public function getLastActivity(): int
    {
        return $this->lastActivity;
    }

    public function isSessionExpired(): bool
    {
        return (time() - $this->lastActivity) >= self::SESSION_LIFETIME;
    }
}
