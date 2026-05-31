<?php

declare(strict_types=1);

namespace App\Security\Sessions;

use App\Entities\UserAccount;
use App\Exceptions\SessionTokenException;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\Request;

final class SessionCredentialManager
{
    private const TOKEN_HASH_METHOD = 'sha256';
    private const RANDOM_BYTES_COUNT = 32;
    private const GRACE_WINDOW = 300;

    public function rotateOnLogin(UserAccount $user): string
    {
        $this->terminateExistingSession();
        $token = $this->mintSessionToken($user->id);
        $this->commitToken($token, $user->id);
        $this->trackLoginActivity($user->id);

        return $token;
    }

    public function rotateOnPasswordUpdate(UserAccount $user): string
    {
        $this->terminateExistingSession();
        $token = $this->mintSessionToken($user->id);
        $this->commitToken($token, $user->id);
        $this->purgePrivilegeData();
        $this->documentSecurityAction($user->id, 'password_updated');

        return $token;
    }

    public function rotateOnEmailUpdate(UserAccount $user, string $updatedEmail): string
    {
        $this->terminateExistingSession();
        $token = $this->mintSessionToken($user->id);
        $this->commitToken($token, $user->id);
        $this->purgePrivilegeData();
        $this->documentSecurityAction($user->id, 'email_updated', [
            'email_hash' => hash(self::TOKEN_HASH_METHOD, $updatedEmail),
        ]);

        return $token;
    }

    public function rotateWithLongLivedToken(UserAccount $user): string
    {
        $this->terminateExistingSession();
        $token = $this->mintSessionToken($user->id);
        $this->commitLongLivedToken($token, $user->id);
        $this->trackLoginActivity($user->id);

        return $token;
    }

    public function rotateAfterSecondFactor(UserAccount $user): string
    {
        $this->terminateExistingSession();
        $token = $this->mintSessionToken($user->id);
        $this->commitToken($token, $user->id);
        $this->documentSecurityAction($user->id, '2fa_success');

        return $token;
    }

    public function rotateAfterAccountRecovery(UserAccount $user): string
    {
        $this->terminateExistingSession();
        $token = $this->mintSessionToken($user->id);
        $this->commitToken($token, $user->id);
        $this->purgePrivilegeData();
        $this->documentSecurityAction($user->id, 'account_recovered');

        return $token;
    }

    public function rotateForApplication(UserAccount $user, array $allowedScopes): string
    {
        $this->terminateExistingSession();
        $token = $this->mintSessionToken($user->id);
        $this->commitApplicationToken($token, $user->id, $allowedScopes);
        $this->trackLoginActivity($user->id);

        return $token;
    }

    private function terminateExistingSession(): void
    {
        Session::clear();
        request()->session()->invalidate();
        request()->session()->regenerate(true);
    }

    private function mintSessionToken(int $userId): string
    {
        $entropy = random_bytes(self::RANDOM_BYTES_COUNT);
        $components = implode('|', [$userId, time(), bin2hex($entropy)]);

        return hash(self::TOKEN_HASH_METHOD, $components);
    }

    private function commitToken(string $token, int $userId): void
    {
        Session::put('auth_token', $token);
        Session::put('auth_user_id', $userId);
        Session::put('auth_issued_at', time());
        Session::put('auth_active', true);
    }

    private function commitLongLivedToken(string $token, int $userId): void
    {
        Session::put('auth_token', hash(self::TOKEN_HASH_METHOD, $token));
        Session::put('auth_user_id', $userId);
        Session::put('auth_issued_at', time());
        Session::put('long_lived', true);
    }

    private function commitApplicationToken(string $token, int $userId, array $scopes): void
    {
        Session::put('app_token', $token);
        Session::put('app_user_id', $userId);
        Session::put('app_scopes', $scopes);
        Session::put('auth_issued_at', time());
    }

    private function configureLongLivedSession(): void
    {
        Session::put('extended_expiry', true);
    }

    private function purgePrivilegeData(): void
    {
        Session::forget('credit_card');
        Session::forget('bank_account');
        Session::forget('ssn');
        Session::forget('tax_identifier');
    }

    private function trackLoginActivity(int $userId): void
    {
        Session::put('activity_timestamp', time());
        Session::put('activity_ip_address', request()->ip());
    }

    private function documentSecurityAction(int $userId, string $actionType, array $metadata = []): void
    {
        Session::put('security_action_type', $actionType);
        Session::put('security_action_timestamp', time());
    }
}
