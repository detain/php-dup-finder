<?php

declare(strict_types=1);

namespace App\Http\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use App\Exceptions\CsrfValidationException;

final class CsrfTokenValidator
{
    private const TOKEN_LENGTH = 32;
    private const TOKEN_ALGORITHM = 'sha256';
    private const SESSION_KEY = '_csrf_token';
    private const HEADER_NAME = 'X-CSRF-TOKEN';
    private const TIMESTAMP_TOLERANCE = 7200;

    public function validate(Request $request): void
    {
        $token = $this->extractToken($request);
        $storedToken = $this->getStoredToken();

        if ($token === null || $storedToken === null) {
            throw new CsrfValidationException('CSRF token is missing');
        }

        if (!$this->isValidSignature($token, $storedToken)) {
            throw new CsrfValidationException('CSRF token signature mismatch');
        }

        if ($this->isExpired($token)) {
            throw new CsrfValidationException('CSRF token has expired');
        }

        $this->validateOrigin($request);
        $this->validateMethod($request);
    }

    public function generate(): string
    {
        $randomBytes = random_bytes(self::TOKEN_LENGTH);
        $timestamp = time();
        $randomPart = bin2hex($randomBytes);

        $token = $this->buildToken($randomPart, $timestamp);
        $this->storeToken($token, $timestamp);

        return $token;
    }

    public function refresh(): string
    {
        $this->invalidateCurrentToken();
        return $this->generate();
    }

    public function regenerate(): string
    {
        $this->invalidateCurrentToken();
        return $this->generate();
    }

    public function getToken(): string
    {
        $storedToken = $this->getStoredToken();

        if ($storedToken !== null && !$this->isExpired($storedToken)) {
            return $storedToken;
        }

        return $this->generate();
    }

    public function getTokenFromSession(): ?string
    {
        return Session::get(self::SESSION_KEY);
    }

    public function validateAjaxRequest(Request $request): void
    {
        $token = $request->header(self::HEADER_NAME) ?? $request->input('_csrf_token');

        if ($token === null) {
            throw new CsrfValidationException('CSRF token missing for AJAX request');
        }

        $storedToken = $this->getStoredToken();

        if (!$this->isValidSignature($token, $storedToken)) {
            throw new CsrfValidationException('CSRF token mismatch for AJAX request');
        }
    }

    public function validateApiRequest(Request $request): void
    {
        $token = $request->header(self::HEADER_NAME);

        if ($token === null) {
            throw new CsrfValidationException('CSRF token missing for API request');
        }

        $storedToken = $this->getStoredToken();

        if (!$this->isValidSignature($token, $storedToken)) {
            throw new CsrfValidationException('CSRF token mismatch for API request');
        }
    }

    private function extractToken(Request $request): ?string
    {
        $token = $request->input('_csrf_token');

        if ($token !== null) {
            return $token;
        }

        $token = $request->header(self::HEADER_NAME);

        if ($token !== null) {
            return $token;
        }

        return $request->header('Authorization');
    }

    private function getStoredToken(): ?string
    {
        return Session::get(self::SESSION_KEY);
    }

    private function storeToken(string $token, int $timestamp): void
    {
        Session::put(self::SESSION_KEY, $token);
        Session::put(self::SESSION_KEY . '_timestamp', $timestamp);
    }

    private function buildToken(string $randomPart, int $timestamp): string
    {
        $signature = hash(self::TOKEN_ALGORITHM, $randomPart . $timestamp);

        return $timestamp . '-' . $randomPart . '-' . $signature;
    }

    private function isValidSignature(string $token, ?string $storedToken): bool
    {
        if ($storedToken === null) {
            return false;
        }

        return hash_equals($storedToken, $token);
    }

    private function isExpired(string $token): bool
    {
        $parts = explode('-', $token);

        if (count($parts) !== 3) {
            return true;
        }

        $timestamp = (int) $parts[0];
        $currentTime = time();

        return ($currentTime - $timestamp) > self::TIMESTAMP_TOLERANCE;
    }

    private function validateOrigin(Request $request): void
    {
        $origin = $request->header('Origin') ?? $request->header('Referer');

        if ($origin === null) {
            return;
        }

        $expectedOrigin = config('app.url');

        if (str_starts_with($origin, 'http://') && str_starts_with($expectedOrigin, 'https://')) {
            throw new CsrfValidationException('CSRF origin mismatch - insecure request to secure endpoint');
        }
    }

    private function validateMethod(Request $request): void
    {
        $safeMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

        if (in_array(strtoupper($request->method()), $safeMethods, true)) {
            throw new CsrfValidationException('CSRF validation not required for safe HTTP methods');
        }
    }

    private function invalidateCurrentToken(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::forget(self::SESSION_KEY . '_timestamp');
    }
}
