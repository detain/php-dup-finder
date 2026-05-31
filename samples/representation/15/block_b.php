<?php
declare(strict_types=1);

namespace App\Customer\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customers')]
#[ORM\Index(columns: ['email'], name: 'idx_customers_email')]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_customers_status_date')]
class Customer
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DELETED = 'deleted';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 254, unique: true)]
    private string $email;

    #[ORM\Column(type: 'string', length: 100)]
    private string $firstName;

    #[ORM\Column(type: 'string', length: 100)]
    private string $lastName;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $referralCode = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $passwordHash;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column(type: 'boolean')]
    private bool $acceptsTerms;

    #[ORM\Column(type: 'boolean')]
    private bool $subscribeNewsletter;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $source = null;

    public function __construct(
        string $id,
        string $email,
        string $firstName,
        string $lastName,
        string $passwordHash
    ) {
        $this->id = $id;
        $this->email = strtolower(trim($email));
        $this->firstName = trim($firstName);
        $this->lastName = trim($lastName);
        $this->passwordHash = $passwordHash;
        $this->status = self::STATUS_PENDING;
        $this->acceptsTerms = false;
        $this->subscribeNewsletter = false;
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
        return trim("{$this->firstName} {$this->lastName}");
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function activate(): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->activatedAt = new \DateTimeImmutable();
    }

    public function recordLogin(): void
    {
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    public function suspend(): void
    {
        $this->status = self::STATUS_SUSPENDED;
    }

    public function updateProfile(string $firstName, string $lastName, ?string $phone): void
    {
        $this->firstName = trim($firstName);
        $this->lastName = trim($lastName);
        $this->phone = $phone;
    }

    public function updatePassword(string $newHash): void
    {
        $this->passwordHash = $newHash;
    }
}
