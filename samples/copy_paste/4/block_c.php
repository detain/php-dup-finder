<?php
declare(strict_types=1);

namespace Acme\Profile;

final class ProfileUpdater
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function rename(string $email, string $newDisplayName): void
    {
        $email = trim(strtolower($email));
        $newDisplayName = trim($newDisplayName);
        if ($newDisplayName === '') {
            throw new \InvalidArgumentException("Display name must not be blank");
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

        $user->setDisplayName($newDisplayName);
        $this->users->save($user);
    }
}
