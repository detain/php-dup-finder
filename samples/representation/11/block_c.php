<?php
declare(strict_types=1);

namespace App\User\ViewModel;

final class UserViewModel
{
    public string $displayName;
    public string $initials;
    public string $avatarUrl;
    public string $roleLabel;
    public string $statusBadge;
    public string $memberSince;
    public string $lastActive;
    public bool $canEdit;
    public bool $canDelete;

    public static function fromUser(
        \App\User\Entity\User $user,
        ?\App\User\Entity\CurrentUser $currentUser = null
    ): self {
        $vm = new self();

        $vm->displayName = trim("{$user->getFirstName()} {$user->getLastName()}");
        $vm->initials = self::generateInitials($user->getFirstName(), $user->getLastName());
        $vm->avatarUrl = $user->getAvatarUrl() ?? self::generateGravatarUrl($user->getEmail());
        $vm->roleLabel = self::getRoleLabel($user->getRole());
        $vm->statusBadge = $user->isActive() ? 'Active' : 'Inactive';
        $vm->memberSince = self::formatRelativeDate($user->getCreatedAt());
        $vm->lastActive = $user->getLastLoginAt() ? self::formatRelativeDate($user->getLastLoginAt()) : 'Never';

        $vm->canEdit = $currentUser?->canEditUser($user) ?? false;
        $vm->canDelete = $currentUser?->canDeleteUser($user) ?? false;

        return $vm;
    }

    private static function generateInitials(string $firstName, string $lastName): string
    {
        $first = mb_strtoupper(mb_substr($firstName, 0, 1));
        $last = mb_strtoupper(mb_substr($lastName, 0, 1));

        return "{$first}{$last}";
    }

    private static function generateGravatarUrl(string $email): string
    {
        $hash = md5(strtolower(trim($email)));
        return "https://www.gravatar.com/avatar/{$hash}?d=mp&s=200";
    }

    private static function getRoleLabel(string $role): string
    {
        return match ($role) {
            'admin' => 'Administrator',
            'editor' => 'Editor',
            'user' => 'User',
            default => ucfirst($role)
        };
    }

    private static function formatRelativeDate(\DateTimeImmutable $date): string
    {
        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 60) {
            return 'Just now';
        }

        if ($diff < 3600) {
            $minutes = (int) floor($diff / 60);
            return "{$minutes} minute" . ($minutes > 1 ? 's' : '') . ' ago';
        }

        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return "{$hours} hour" . ($hours > 1 ? 's' : '') . ' ago';
        }

        if ($diff < 604800) {
            $days = (int) floor($diff / 86400);
            return "{$days} day" . ($days > 1 ? 's' : '') . ' ago';
        }

        return $date->format('M j, Y');
    }

    public function toViewData(): array
    {
        return [
            'display_name' => $this->displayName,
            'initials' => $this->initials,
            'avatar_url' => $this->avatarUrl,
            'role_label' => $this->roleLabel,
            'status_badge' => $this->statusBadge,
            'member_since' => $this->memberSince,
            'last_active' => $this->lastActive,
            'can_edit' => $this->canEdit,
            'can_delete' => $this->canDelete
        ];
    }

    public function getAvatarUrlWithSize(int $size): string
    {
        if (str_contains($this->avatarUrl, 'gravatar.com')) {
            return str_replace('?s=200', "?s={$size}", $this->avatarUrl);
        }

        return $this->avatarUrl;
    }
}
