<?php

declare(strict_types=1);

namespace App\Authentication\Sessions;

use App\Models\User;
use App\Exceptions\SessionException;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Hash;

final class SessionTokenRegenerator
{
    private const TOKEN_LENGTH = 32;
    private const TOKEN_ALGORITHM = 'sha256';
    private const OLD_TOKEN_GRACE_PERIOD = 300;

    public function regenerateAfterLogin(User $user): string
    {
        $this->invalidateCurrentSession();
        $newToken = $this->generateSecureToken($user->id);
        $this->storeSessionToken($newToken, $user->id);
        $this->recordSessionActivity($user->id);

        return $newToken;
    }

    public function regenerateAfterPasswordChange(User $user): string
    {
        $this->invalidateCurrentSession();
        $newToken = $this->generateSecureToken($user->id);
        $this->storeSessionToken($newToken, $user->id);
        $this->clearSensitiveSessionData();
        $this->recordSecurityEvent($user->id, 'password_changed');

        return $newToken;
    }

    public function regenerateAfterEmailChange(User $user, string $newEmail): string
    {
        $this->invalidateCurrentSession();
        $newToken = $this->generateSecureToken($user->id);
        $this->storeSessionToken($newToken, $user->id);
        $this->clearSensitiveSessionData();
        $this->recordSecurityEvent($user->id, 'email_changed', ['new_email_hash' => hash(self::TOKEN_ALGORITHM, $newEmail)]);

        return $newToken;
    }

    public function regenerateWithRememberToken(User $user, bool $rememberMe): string
    {
        $this->invalidateCurrentSession();
        $newToken = $this->generateSecureToken($user->id);

        if ($rememberMe) {
            $this->storeRememberToken($newToken, $user->id);
            $this->setExtendedSessionLifetime();
        } else {
            $this->storeSessionToken($newToken, $user->id);
        }

        $this->recordSessionActivity($user->id);

        return $newToken;
    }

    public function regenerateWithMfaVerification(User $user): string
    {
        $this->invalidateCurrentSession();
        $newToken = $this->generateSecureToken($user->id);
        $this->storeSessionToken($newToken, $user->id);
        $this->recordSecurityEvent($user->id, 'mfa_completed');

        return $newToken;
    }

    public function regenerateAfterAccountUnlock(User $user): string
    {
        $this->invalidateCurrentSession();
        $newToken = $this->generateSecureToken($user->id);
        $this->storeSessionToken($newToken, $user->id);
        $this->recordSecurityEvent($user->id, 'account_unlocked');

        return $newToken;
    }

    public function regenerateForApiAccess(User $user, array $scopes): string
    {
        $this->invalidateCurrentSession();
        $newToken = $this->generateSecureToken($user->id);
        $this->storeApiSessionToken($newToken, $user->id, $scopes);
        $this->recordSessionActivity($user->id);

        return $newToken;
    }

    private function invalidateCurrentSession(): void
    {
        Session::flush();
        session()->regenerate(true);
    }

    private function generateSecureToken(int $userId): string
    {
        $randomBytes = random_bytes(self::TOKEN_LENGTH);
        $material = $userId . '|' . time() . '|' . bin2hex($randomBytes);

        return hash(self::TOKEN_ALGORITHM, $material);
    }

    private function storeSessionToken(string $token, int $userId): void
    {
        Session::put('auth_token', $token);
        Session::put('auth_user_id', $userId);
        Session::put('auth_token_issued', time());
        Session::put('auth_token_active', true);
    }

    private function storeRememberToken(string $token, int $userId): void
    {
        $hashedToken = hash(self::TOKEN_ALGORITHM, $token);
        Session::put('remember_token', $hashedToken);
        Session::put('auth_user_id', $userId);
        Session::put('remember_session', true);
        Session::put('auth_token_issued', time());
    }

    private function storeApiSessionToken(string $token, int $userId, array $scopes): void
    {
        Session::put('api_token', $token);
        Session::put('api_user_id', $userId);
        Session::put('api_scopes', $scopes);
        Session::put('api_token_issued', time());
    }

    private function setExtendedSessionLifetime(): void
    {
        Session::put('extended_lifetime', true);
        Session::getHandler()->gc(86400 * 30);
    }

    private function clearSensitiveSessionData(): void
    {
        Session::forget('payment_methods');
        Session::forget('billing_info');
        Session::forget('personal_docs');
        Session::forget('security_questions');
    }

    private function recordSessionActivity(int $userId): void
    {
        Session::put('last_activity', time());
        Session::put('last_activity_ip', request()->ip());
    }

    private function recordSecurityEvent(int $userId, string $eventType, array $metadata = []): void
    {
        Session::put('last_security_event', $eventType);
        Session::put('last_security_event_time', time());
    }
}
