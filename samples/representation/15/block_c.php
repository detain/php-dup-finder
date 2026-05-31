<?php
declare(strict_types=1);

namespace App\Customer\Profile;

final class CustomerProfileViewModel
{
    public string $id;
    public string $email;
    public string $firstName;
    public string $lastName;
    public string $fullName;
    public ?string $phone;
    public string $memberSince;
    public string $lastLogin;
    public string $accountStatus;
    public string $statusBadgeClass;
    public bool $isActive;
    public bool $isSuspended;
    public bool $canLogin;

    public static function fromCustomer(\App\Customer\Entity\Customer $customer): self
    {
        $vm = new self();
        $vm->id = $customer->getId();
        $vm->email = $customer->getEmail();
        $vm->firstName = $customer->getFirstName();
        $vm->lastName = $customer->getLastName();
        $vm->fullName = $customer->getFullName();
        $vm->phone = $customer->getPhone();
        $vm->memberSince = self::formatDate($customer->getCreatedAt());
        $vm->lastLogin = $customer->getLastLoginAt()
            ? self::formatDate($customer->getLastLoginAt())
            : 'Never';
        $vm->accountStatus = self::getStatusLabel($customer->getStatus());
        $vm->statusBadgeClass = self::getStatusBadgeClass($customer->getStatus());
        $vm->isActive = $customer->isActive();
        $vm->isSuspended = $customer->getStatus() === 'suspended';
        $vm->canLogin = $customer->isActive();

        return $vm;
    }

    private static function formatDate(\DateTimeImmutable $date): string
    {
        return $date->format('F j, Y');
    }

    private static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Pending Activation',
            'active' => 'Active',
            'suspended' => 'Suspended',
            'deleted' => 'Deleted',
            default => ucfirst($status)
        };
    }

    private static function getStatusBadgeClass(string $status): string
    {
        return match ($status) {
            'pending' => 'badge-warning',
            'active' => 'badge-success',
            'suspended' => 'badge-danger',
            'deleted' => 'badge-secondary',
            default => 'badge-secondary'
        };
    }

    public function getInitials(): string
    {
        $first = mb_strtoupper(mb_substr($this->firstName, 0, 1));
        $last = mb_strtoupper(mb_substr($this->lastName, 0, 1));
        return "{$first}{$last}";
    }

    public function getGravatarUrl(int $size = 200): string
    {
        $hash = md5(strtolower($this->email));
        return "https://www.gravatar.com/avatar/{$hash}?d=mp&s={$size}";
    }

    public function toViewData(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'full_name' => $this->fullName,
            'phone' => $this->phone,
            'member_since' => $this->memberSince,
            'last_login' => $this->lastLogin,
            'account_status' => $this->accountStatus,
            'status_badge_class' => $this->statusBadgeClass,
            'is_active' => $this->isActive,
            'initials' => $this->getInitials(),
            'gravatar_url' => $this->getGravatarUrl()
        ];
    }
}
