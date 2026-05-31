<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Exceptions\TokenRegenerationException;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\Request;

final class SessionRenewalService
{
    private const HASHING = 'sha256';
    private const BYTES_REQUIRED = 32;
    private const GRACE_PERIOD_SECONDS = 300;

    public function renewOnAuthentication(User $user): string
    {
        $this->destroyCurrentSession();
        $newToken = $this->createSessionToken($user->id);
        $this->persistToken($newToken, $user->id);
        $this->logAccess($user->id);

        return $newToken;
    }

    public function renewOnCredentialUpdate(User $user): string
    {
        $this->destroyCurrentSession();
        $newToken = $this->createSessionToken($user->id);
        $this->persistToken($newToken, $user->id);
        $this->scrubSensitiveData();
        $this->logSecurityIncident($user->id, 'credentials_updated');

        return $newToken;
    }

    public function renewOnEmailVerification(User $user, string $verifiedEmail): string
    {
        $this->destroyCurrentSession();
        $newToken = $this->createSessionToken($user->id);
        $this->persistToken($newToken, $user->id);
        $this->scrubSensitiveData();
        $this->logSecurityIncident($user->id, 'email_verified', ['email_hash' => hash(self::HASHING_ALGO, $verifiedEmail)]);

        return $newToken;
    }

    public function renewWithPersistentRemember(User $user): string
    {
        $this->destroyCurrentSession();
        $newToken = $this->createSessionToken($user->id);
        $this->persistRememberToken($newToken, $user->id);
        $this->configureExtendedLifetime();
        $this->logAccess($user->id);

        return $newToken;
    }

    public function renewAfterMfa(User $user): string
    {
        $this->destroyCurrentSession();
        $newToken = $this->createSessionToken($user->id);
        $this->persistToken($newToken, $user->id);
        $this->logSecurityIncident($user->id, 'mfa_verified');

        return $newToken;
    }

    public function renewAfterAccountRecovery(User $user): string
    {
        $this->destroyCurrentSession();
        $newToken = $this->createSessionToken($user->id);
        $this->persistToken($newToken, $user->id);
        $this->scrubSensitiveData();
        $this->logSecurityIncident($user->id, 'account_recovered');

        return $newToken;
    }

    public function renewForServiceAuth(User $user, array $permissions): string
    {
        $this->destroyCurrentSession();
        $newToken = $this->createSessionToken($user->id);
        $this->persistServiceToken($newToken, $user->id, $permissions);
        $this->logAccess($user->id);

        return $newToken;
    }

    private function destroyCurrentSession(): void
    {
        Session::flush();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }

    private function createSessionToken(int $userId): string
    {
        $randomData = random_bytes(self::BYTES_REQUIRED);
        $concatenated = implode('|', [$userId, time(), bin2hex($randomData)]);

        return hash(self::HASHING_ALGO, $concatenated);
    }

    private function persistToken(string $token, int $userId): void
    {
        Session::put('session_token', $token);
        Session::put('session_user_id', $userId);
        Session::put('session_created_at', time());
        Session::put('session_valid', true);
    }

    private function persistRememberToken(string $token, int $userId): void
    {
        Session::put('remember_token', hash(self::HASHING_ALGO, $token));
        Session::put('session_user_id', $userId);
        Session::put('remember_me', true);
        Session::put('session_created_at', time());
    }

    private function persistServiceToken(string $token, int $userId, array $permissions): void
    {
        Session::put('service_token', $token);
        Session::put('service_user_id', $userId);
        Session::put('service_permissions', $permissions);
        Session::put('session_created_at', time());
    }

    private function configureExtendedLifetime(): void
    {
        Session::put('persistent_session', true);
    }

    private function scrubSensitiveData(): void
    {
        Session::forget('card_number');
        Session::forget('account_routing');
        Session::forget('social_security');
        Session::forget('tax_id');
    }

    private function logAccess(int $userId): void
    {
        Session::put('last_request_at', time());
        Session::put('last_request_ip', request()->ip());
    }

    private function logSecurityIncident(int $userId, string $incidentType, array $context = []): void
    {
        Session::put('last_security_incident_type', $incidentType);
        Session::put('last_security_incident_at', time());
    }
}
