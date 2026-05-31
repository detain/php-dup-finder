<?php
declare(strict_types=1);

namespace Api\Models;

use Doctrine\ORM\Mapping as ORM;

/**
 * Customer entity representing a registered user account
 *
 * @ORM\Entity(repositoryClass="CustomerRepository")
 * @ORM\Table(name="customers")
 * @ORM\HasLifecycleCallbacks
 */
class Customer
{
    /**
     * Unique identifier for the customer
     *
     * @var int
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Customer's email address used for login and notifications
     *
     * Must be a valid RFC 5321 email format, max 254 characters.
     * Stored in lowercase for consistent lookup.
     *
     * @var string
     * @ORM\Column(type="string", length=254, unique=true)
     */
    private string $email;

    /**
     * Customer's first/given name
     *
     * Maximum 100 characters, may contain letters (a-z, A-Z),
     * spaces, hyphens, and apostrophes only.
     *
     * @var string
     * @ORM\Column(type="string", length=100)
     */
    private string $firstName;

    /**
     * Customer's last/family name
     *
     * Maximum 100 characters, may contain letters (a-z, A-Z),
     * spaces, hyphens, and apostrophes only.
     *
     * @var string
     * @ORM\Column(type="string", length=100)
     */
    private string $lastName;

    /**
     * Customer's phone number in international E.164 format
     *
     * Optional field. Stored as + followed by 10-15 digits.
     * Examples: +14155551234, +442071234567
     *
     * @var string|null
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private ?string $phone = null;

    /**
     * Argon2id hashed password
     *
     * Hashed using password_hash() with PASSWORD_ARGON2ID.
     * Never store plain text passwords.
     *
     * @var string
     * @ORM\Column(type="string")
     */
    private string $passwordHash;

    /**
     * Whether customer has consented to marketing communications
     *
     * When true, customer may receive promotional emails.
     * Must be explicitly set during registration and changeable
     * in account settings.
     *
     * @var bool
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private bool $marketingConsent = false;

    /**
     * Referral code used during signup
     *
     * Optional 5-20 character alphanumeric code that identifies
     * which referral source brought this customer.
     *
     * @var string|null
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private ?string $referralCode = null;

    /**
     * Timestamp when customer account was created
     *
     * @var \DateTimeImmutable
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * Timestamp when customer profile was last updated
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower(trim($email));
        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = trim($firstName);
        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = trim($lastName);
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        if ($phone !== null) {
            $phone = preg_replace('/\D/', '', $phone);
            if (strlen($phone) >= 10 && strlen($phone) <= 15) {
                $this->phone = '+' . ltrim($phone, '+');
            }
        } else {
            $this->phone = null;
        }
        return $this;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $hash): self
    {
        $this->passwordHash = $hash;
        return $this;
    }

    public function hasMarketingConsent(): bool
    {
        return $this->marketingConsent;
    }

    public function setMarketingConsent(bool $consent): self
    {
        $this->marketingConsent = $consent;
        return $this;
    }

    public function getReferralCode(): ?string
    {
        return $this->referralCode;
    }

    public function setReferralCode(?string $code): self
    {
        $this->referralCode = $code;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @ORM\PreUpdate
     */
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
