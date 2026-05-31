<?php

declare(strict_types=1);

namespace App\Domain\User\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Domain\User\ValueObject\UserId;

/**
 * Doctrine entity for User.
 * This entity definition is duplicated from the MySQL DDL above.
 * Changes here should be reflected in:
 * - MySQL DDL: migrations/V1__create_users_table.sql
 * - API schema: paths./users.register
 * - JSON Schema: schemas/user-registration.json
 *
 * @ORM\Entity
 * @ORM\Table(name="users")
 * @ORM\Index(name="idx_email", columns={"email"}, unique=true)
 * @ORM\Index(name="idx_status", columns={"status"})
 * @ORM\Index(name="idx_created_at", columns={"created_at"})
 */
class User
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=36)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", length=255, unique=true)
     */
    private string $email;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $passwordHash;

    /**
     * @ORM\Column(type="string", length=100)
     */
    private string $firstName;

    /**
     * @ORM\Column(type="string", length=100)
     */
    private string $lastName;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private ?string $phoneNumber = null;

    /**
     * @ORM\Column(type="string", length=2)
     */
    private string $countryCode = 'US';

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $status = 'pending';

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $referralCode = null;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $preferences = null;

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $organizationId = null;

    public function __construct(
        string $email,
        string $firstName,
        string $lastName,
        string $passwordHash
    ) {
        $this->id = UserId::generate()->toString();
        $this->email = $email;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->passwordHash = $passwordHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): void
    {
        $this->phoneNumber = $phoneNumber;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function markEmailAsVerified(): void
    {
        $this->emailVerifiedAt = new \DateTimeImmutable();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPreferences(): array
    {
        return $this->preferences ?? [];
    }

    public function setPreferences(array $preferences): void
    {
        $this->preferences = $preferences;
    }
}
