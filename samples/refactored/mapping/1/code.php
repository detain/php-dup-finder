<?php
declare(strict_types=1);

namespace App\Core\Mapper;

use App\Domain\Entity\User;
use App\Core\DTO\DTOInterface;

interface UserMappingStrategy
{
    public function getDateFormat(): string;
    public function shouldIncludeActivity(): bool;
    public function formatRoles(array $roles): array;
}

final readonly class UnifiedUserMapper
{
    private const DATE_ATOM = 'Y-m-d\TH:i:sP';
    private const DATE_DISPLAY = 'M d, Y';
    private const DATE_TIME_DISPLAY = 'M d, Y H:i';

    public function __construct(
        private UserRoleMapper $roleMapper,
    ) {}

    public function map(User $user, DTOInterface $target): DTOInterface
    {
        $target->id = $user->getId()->toString();
        $target->email = $user->getEmail();
        $target->firstName = $user->getProfile()->getFirstName();
        $target->lastName = $user->getProfile()->getLastName();
        $target->displayName = $user->getProfile()->getDisplayName();
        $target->avatarUrl = $user->getProfile()->getAvatarUrl();
        $target->phone = $user->getProfile()->getPhone();
        $target->status = $user->getStatus()->value;
        $target->roles = $this->roleMapper->toDtoList($user->getRoles());
        $target->createdAt = $user->getCreatedAt()->format(self::DATE_ATOM);
        $target->updatedAt = $user->getUpdatedAt()->format(self::DATE_ATOM);
        $target->lastLoginAt = $user->getLastLoginAt()?->format(self::DATE_ATOM);
        $target->emailVerified = $user->isEmailVerified();
        $target->twoFactorEnabled = $user->isTwoFactorEnabled();

        return $target;
    }

    public function mapWithStrategy(User $user, DTOInterface $target, UserMappingStrategy $strategy): DTOInterface
    {
        $this->map($user, $target);

        if ($strategy->shouldIncludeActivity()) {
            $target->recentActivity = $this->getRecentActivity($user->getId());
        }

        if ($strategy->getDateFormat() === self::DATE_DISPLAY) {
            $target->createdAt = $user->getCreatedAt()->format(self::DATE_DISPLAY);
            $target->updatedAt = $user->getUpdatedAt()->format(self::DATE_DISPLAY);
            $target->lastLoginAt = $user->getLastLoginAt()?->format(self::DATE_TIME_DISPLAY);
        }

        if (!empty($target->roles) && method_exists($target, 'setRoles')) {
            $target->setRoles($strategy->formatRoles($target->roles));
        }

        return $target;
    }

    private function getRecentActivity(string $userId): array
    {
        return [];
    }
}
