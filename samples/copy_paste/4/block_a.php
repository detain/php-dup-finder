<?php
declare(strict_types=1);

namespace Acme\Auth;

final class PasswordResetService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenIssuer $tokens,
        private readonly Mailer $mailer,
    ) {
    }

    public function requestReset(string $email): string
    {
        $email = trim(strtolower($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email address");
        }

        // ---- BEGIN copy-pasted lookup-or-throw ----
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            error_log("[lookup] missing user for email={$email}");
            throw new UserNotFoundException(sprintf(
                'No user account is associated with %s',
                $email,
            ));
        }
        if (!$user->isActive()) {
            throw new UserNotFoundException(sprintf(
                'Account for %s is not active',
                $email,
            ));
        }
        // ---- END copy-pasted lookup-or-throw ----

        $token = $this->tokens->issue($user->id(), 'password-reset', 3600);
        $this->mailer->sendResetLink($user, $token);
        return $token;
    }
}
