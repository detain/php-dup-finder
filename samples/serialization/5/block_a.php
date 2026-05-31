<?php

declare(strict_types=1);

namespace App\Session;

class UserSessionManager
{
    private SessionStore $session;

    public function __construct(SessionStore $session)
    {
        $this->session = $session;
    }

    public function saveUserState(User $user): void
    {
        $state = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'avatar_url' => $user->getAvatarUrl(),
            'is_active' => $user->isActive(),
            'roles' => $user->getRoles(),
            'created_at' => $user->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $user->getUpdatedAt()?->format('Y-m-d H:i:s'),
            'saved_at' => time()
        ];

        $this->session->set('user_state', $state);
        $this->session->set('user_id', $user->getId());
        $this->session->set('user_roles', $user->getRoles());
    }

    public function restoreUserState(): ?array
    {
        $state = $this->session->get('user_state');

        if ($state === null) {
            return null;
        }

        $state['created_at'] = new \DateTimeImmutable($state['created_at']);

        if ($state['updated_at'] !== null) {
            $state['updated_at'] = new \DateTimeImmutable($state['updated_at']);
        }

        unset($state['saved_at']);

        return $state;
    }

    public function clearUserState(): void
    {
        $this->session->remove('user_state');
        $this->session->remove('user_id');
        $this->session->remove('user_roles');
    }

    public function hasUserState(): bool
    {
        return $this->session->has('user_state');
    }

    public function getUserId(): ?string
    {
        return $this->session->get('user_id');
    }

    public function isUserInRole(string $role): bool
    {
        $roles = $this->session->get('user_roles', []);

        return in_array($role, $roles, true);
    }

    public function refreshUserState(User $user): void
    {
        $this->saveUserState($user);
    }

    public function getUserStateTimestamp(): ?int
    {
        $state = $this->session->get('user_state');

        if ($state === null || !isset($state['saved_at'])) {
            return null;
        }

        return $state['saved_at'];
    }
}
