<?php
declare(strict_types=1);

namespace Acme\Auth;

final class LoginAttemptHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly LoginAuditor $auditor,
    ) {
    }

    public function attempt(string $email, string $password): SessionToken
    {
        $email = trim(strtolower($email));
        if ($email === '' || strlen($password) < 1) {
            throw new \InvalidArgumentException("Email and password required");
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

        if (!$this->hasher->verify($password, $user->passwordHash())) {
            $this->auditor->failed($user->id());
            throw new InvalidCredentialsException();
        }
        return new SessionToken($user->id(), bin2hex(random_bytes(16)));
    }
}
