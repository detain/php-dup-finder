<?php

declare(strict_types=1);

namespace App\Foundation\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use App\Exceptions\CsrfTokenException;

final class FormCsrfVerifier
{
    private const TOKEN_BYTES = 32;
    private const HASH_FUNCTION = 'sha256';
    private const STORAGE_KEY = 'form_csrf';
    private const TOKEN_HEADER = 'X-CSRF-TOKEN';
    private const MAX_AGE_SECONDS = 7200;

    public function verify(Request $request): void
    {
        $providedToken = $this->obtainToken($request);
        $sessionToken = $this->obtainSessionToken();

        $this->ensureTokenExists($providedToken, $sessionToken);
        $this->ensureTokenSignature($providedToken, $sessionToken);
        $this->ensureTokenFreshness($providedToken);
        $this->ensureRequestOrigin($request);
    }

    public function create(): string
    {
        $entropy = random_bytes(self::TOKEN_BYTES);
        $moment = time();
        $encodedEntropy = bin2hex($entropy);

        $token = $this->constructToken($encodedEntropy, $moment);
        $this->persistToken($token, $moment);

        return $token;
    }

    public function renew(): string
    {
        $this->clearToken();
        return $this->create();
    }

    public function getCurrent(): string
    {
        $existing = $this->obtainSessionToken();

        if ($existing !== null && !$this->isTokenExpired($existing)) {
            return $existing;
        }

        return $this->create();
    }

    public function getSessionToken(): ?string
    {
        return Session::get(self::STORAGE_KEY);
    }

    public function verifyAjax(Request $request): void
    {
        $token = $request->header(self::TOKEN_HEADER) ?? $request->post('_csrf_token');

        if ($token === null) {
            throw new CsrfTokenException('Token missing from AJAX headers');
        }

        $stored = $this->obtainSessionToken();

        if (!hash_equals($stored ?? '', $token)) {
            throw new CsrfTokenException('Token mismatch in AJAX request');
        }
    }

    public function verifyApi(Request $request): void
    {
        $token = $request->header(self::TOKEN_HEADER);

        if ($token === null) {
            throw new CsrfTokenException('Token missing from API headers');
        }

        $stored = $this->obtainSessionToken();

        if (!hash_equals($stored ?? '', $token)) {
            throw new CsrfTokenException('Token mismatch in API request');
        }
    }

    private function obtainToken(Request $request): ?string
    {
        $fromPost = $request->post('_csrf_token');

        if ($fromPost !== null) {
            return $fromPost;
        }

        $fromHeader = $request->header(self::TOKEN_HEADER);

        if ($fromHeader !== null) {
            return $fromHeader;
        }

        return null;
    }

    private function obtainSessionToken(): ?string
    {
        return Session::get(self::STORAGE_KEY);
    }

    private function constructToken(string $entropy, int $timestamp): string
    {
        $signature = hash(self::HASH_FUNCTION, $entropy . $timestamp);

        return $timestamp . '-' . $entropy . '-' . $signature;
    }

    private function persistToken(string $token, int $timestamp): void
    {
        Session::put(self::STORAGE_KEY, $token);
        Session::put(self::STORAGE_KEY . '_created', $timestamp);
    }

    private function ensureTokenExists(?string $provided, ?string $stored): void
    {
        if ($provided === null || $stored === null) {
            throw new CsrfTokenException('CSRF token absent from request or session');
        }
    }

    private function ensureTokenSignature(string $provided, ?string $stored): void
    {
        if (!hash_equals($stored, $provided)) {
            throw new CsrfTokenException('CSRF token signature validation failed');
        }
    }

    private function ensureTokenFreshness(string $token): void
    {
        if ($this->isTokenExpired($token)) {
            throw new CsrfTokenException('CSRF token has exceeded maximum age');
        }
    }

    private function isTokenExpired(string $token): bool
    {
        $components = explode('-', $token);

        if (count($components) !== 3) {
            return true;
        }

        $timestamp = (int) $components[0];
        $age = time() - $timestamp;

        return $age > self::MAX_AGE_SECONDS;
    }

    private function ensureRequestOrigin(Request $request): void
    {
        $origin = $request->header('Origin') ?? $request->header('Referer');

        if ($origin === null) {
            return;
        }

        $appUrl = config('app.url');

        if (str_starts_with($origin, 'http://') && str_starts_with($appUrl, 'https://')) {
            throw new CsrfTokenException('Origin header does not match application URL');
        }
    }

    private function clearToken(): void
    {
        Session::forget(self::STORAGE_KEY);
        Session::forget(self::STORAGE_KEY . '_created');
    }
}
