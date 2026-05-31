<?php

declare(strict_types=1);

namespace App\Web\Forms;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use App\Exceptions\TokenVerificationException;

final class FormTokenManager
{
    private const RANDOM_BYTES = 32;
    private const HASH_MODE = 'sha256';
    private const SESSION_INDEX = 'form_security_token';
    private const HEADER_KEY = 'X-CSRF-TOKEN';
    private const TOKEN_VALIDITY_WINDOW = 7200;

    public function validate(Request $request): void
    {
        $submitted = $this->extractToken($request);
        $saved = $this->fetchSavedToken();

        $this->checkTokenPresence($submitted, $saved);
        $this->checkTokenEquivalence($submitted, $saved);
        $this->checkTokenAge($submitted);
        $this->checkRequestOrigin($request);
    }

    public function issue(): string
    {
        $bytes = random_bytes(self::RANDOM_BYTES);
        $now = time();
        $encoded = bin2hex($bytes);

        $token = $this->assembleToken($encoded, $now);
        $this->saveToken($token, $now);

        return $token;
    }

    public function refresh(): string
    {
        $this->discardToken();
        return $this->issue();
    }

    public function current(): string
    {
        $saved = $this->fetchSavedToken();

        if ($saved !== null && !$this->isStale($saved)) {
            return $saved;
        }

        return $this->issue();
    }

    public function saved(): ?string
    {
        return Session::get(self::SESSION_INDEX);
    }

    public function validateAjax(Request $request): void
    {
        $token = $request->header(self::HEADER_KEY) ?? $request->input('_csrf_token');

        if ($token === null) {
            throw new TokenVerificationException('Security token absent from AJAX call');
        }

        $stored = $this->fetchSavedToken();

        if (!hash_equals($stored ?? '', $token)) {
            throw new TokenVerificationException('Security token mismatch in AJAX call');
        }
    }

    public function validateService(Request $request): void
    {
        $token = $request->header(self::HEADER_KEY);

        if ($token === null) {
            throw new TokenVerificationException('Security token absent from service call');
        }

        $stored = $this->fetchSavedToken();

        if (!hash_equals($stored ?? '', $token)) {
            throw new TokenVerificationException('Security token mismatch in service call');
        }
    }

    private function extractToken(Request $request): ?string
    {
        $fromBody = $request->input('_csrf_token');

        if ($fromBody !== null) {
            return $fromBody;
        }

        $fromHeader = $request->header(self::HEADER_KEY);

        if ($fromHeader !== null) {
            return $fromHeader;
        }

        return null;
    }

    private function fetchSavedToken(): ?string
    {
        return Session::get(self::SESSION_INDEX);
    }

    private function assembleToken(string $entropy, int $timestamp): string
    {
        $checksum = hash(self::HASH_MODE, $entropy . $timestamp);

        return $timestamp . '-' . $entropy . '-' . $checksum;
    }

    private function saveToken(string $token, int $timestamp): void
    {
        Session::put(self::SESSION_INDEX, $token);
        Session::put(self::SESSION_INDEX . '_when', $timestamp);
    }

    private function checkTokenPresence(?string $submitted, ?string $saved): void
    {
        if ($submitted === null || $saved === null) {
            throw new TokenVerificationException('Form security token is missing');
        }
    }

    private function checkTokenEquivalence(string $submitted, ?string $saved): void
    {
        if (!hash_equals($saved, $submitted)) {
            throw new TokenVerificationException('Form security token is invalid');
        }
    }

    private function checkTokenAge(string $token): void
    {
        if ($this->isStale($token)) {
            throw new TokenVerificationException('Form security token has expired');
        }
    }

    private function isStale(string $token): bool
    {
        $segments = explode('-', $token);

        if (count($segments) !== 3) {
            return true;
        }

        $when = (int) $segments[0];

        return (time() - $when) > self::TOKEN_VALIDITY_WINDOW;
    }

    private function checkRequestOrigin(Request $request): void
    {
        $origin = $request->header('Origin') ?? $request->header('Referer');

        if ($origin === null) {
            return;
        }

        $baseUrl = config('app.url');

        if (str_starts_with($origin, 'http://') && str_starts_with($baseUrl, 'https://')) {
            throw new TokenVerificationException('Request origin does not match application URL');
        }
    }

    private function discardToken(): void
    {
        Session::forget(self::SESSION_INDEX);
        Session::forget(self::SESSION_INDEX . '_when');
    }
}
