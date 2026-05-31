<?php
declare(strict_types=1);

namespace App\UI\Dashboard\Presenter;

use App\Domain\Entity\User;
use App\UI\Dashboard\ViewModel\UserProfileViewModel;
use App\UI\Dashboard\ViewModel\UserListItemViewModel;
use App\UI\Dashboard\ViewModel\UserSettingsViewModel;

final readonly class UserPresenter
{
    public function __construct(
        private UserPreferencesFormatter $preferencesFormatter,
        private UserActivityTracker $activityTracker,
    ) {}

    public function presentProfile(User $user): UserProfileViewModel
    {
        $viewModel = new UserProfileViewModel();
        $viewModel->id = $user->getId()->toString();
        $viewModel->email = $user->getEmail();
        $viewModel->firstName = $user->getProfile()->getFirstName();
        $viewModel->lastName = $user->getProfile()->getLastName();
        $viewModel->displayName = $user->getProfile()->getDisplayName();
        $viewModel->avatarUrl = $user->getProfile()->getAvatarUrl();
        $viewModel->phone = $user->getProfile()->getPhone();
        $viewModel->status = $user->getStatus()->value;
        $viewModel->roles = $this->formatRolesForDisplay($user->getRoles());
        $viewModel->createdAt = $user->getCreatedAt()->format('M d, Y');
        $viewModel->updatedAt = $user->getUpdatedAt()->format('M d, Y');
        $viewModel->lastLoginAt = $user->getLastLoginAt()?->format('M d, Y H:i');
        $viewModel->emailVerified = $user->isEmailVerified();
        $viewModel->twoFactorEnabled = $user->isTwoFactorEnabled();
        $viewModel->recentActivity = $this->activityTracker->getRecentForUser($user->getId());

        return $viewModel;
    }

    public function presentListItem(User $user): UserListItemViewModel
    {
        $item = new UserListItemViewModel();
        $item->id = $user->getId()->toString();
        $item->email = $user->getEmail();
        $item->firstName = $user->getProfile()->getFirstName();
        $item->lastName = $user->getProfile()->getLastName();
        $item->displayName = $user->getProfile()->getDisplayName();
        $item->avatarUrl = $user->getProfile()->getAvatarUrl();
        $item->phone = $user->getProfile()->getPhone();
        $item->status = $user->getStatus()->value;
        $item->roles = $this->formatRolesForDisplay($user->getRoles());
        $item->createdAt = $user->getCreatedAt()->format('M d, Y');
        $item->updatedAt = $user->getUpdatedAt()->format('M d, Y');
        $item->lastLoginAt = $user->getLastLoginAt()?->format('M d, Y H:i');
        $item->emailVerified = $user->isEmailVerified();
        $item->twoFactorEnabled = $user->isTwoFactorEnabled();

        return $item;
    }

    public function presentSettings(User $user): UserSettingsViewModel
    {
        $settings = new UserSettingsViewModel();
        $settings->id = $user->getId()->toString();
        $settings->email = $user->getEmail();
        $settings->firstName = $user->getProfile()->getFirstName();
        $settings->lastName = $user->getProfile()->getLastName();
        $settings->displayName = $user->getProfile()->getDisplayName();
        $settings->avatarUrl = $user->getProfile()->getAvatarUrl();
        $settings->phone = $user->getProfile()->getPhone();
        $settings->status = $user->getStatus()->value;
        $settings->roles = $this->formatRolesForDisplay($user->getRoles());
        $settings->createdAt = $user->getCreatedAt()->format('M d, Y');
        $settings->updatedAt = $user->getUpdatedAt()->format('M d, Y');
        $settings->lastLoginAt = $user->getLastLoginAt()?->format('M d, Y H:i');
        $settings->emailVerified = $user->isEmailVerified();
        $settings->twoFactorEnabled = $user->isTwoFactorEnabled();
        $settings->preferences = $this->preferencesFormatter->format($user->getPreferences());

        return $settings;
    }

    private function formatRolesForDisplay(array $roles): array
    {
        return array_map(fn($role) => $role->getLabel(), $roles);
    }
}
