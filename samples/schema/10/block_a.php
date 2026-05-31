<?php

declare(strict_types=1);

namespace App\Domain\Subscriptions\Entity;

use App\Domain\Subscriptions\ValueObject\PlanId;
use App\Domain\Subscriptions\ValueObject\SubscriptionId;

/**
 * Doctrine entity for subscription plans.
 * This entity is duplicated in:
 * - Database table: subscription_plans
 * - Payment gateway plan configurations
 * - API documentation schemas
 * - Billing service configurations
 *
 * @ORM\Entity(repositoryClass=SubscriptionPlanRepository::class)
 * @ORM\Table(name="subscription_plans")
 * @ORM\Index(name="idx_plan_slug", columns={"slug"}, unique=true)
 * @ORM\Index(name="idx_plan_active", columns={"is_active"})
 * @ORM\Index(name="idx_plan_tier", columns={"tier"})
 */
class SubscriptionPlan
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=36)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    private string $slug;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $name;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $billingInterval;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    private float $price;

    /**
     * @ORM\Column(type="char", length=3)
     */
    private string $currency = 'USD';

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $trialDays = null;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $gracePeriodDays = null;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $tier = 'standard';

    /**
     * @ORM\Column(type="json")
     */
    private array $features = [];

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $usageLimits = null;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $constraints = null;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $isActive = true;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $isPublic = true;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $maxUsers = null;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $maxStorageGb = null;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $maxApiCalls = null;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $availableFrom = null;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $availableUntil = null;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $slug,
        string $name,
        float $price,
        string $billingInterval
    ) {
        $this->id = PlanId::generate()->toString();
        $this->slug = $slug;
        $this->name = $name;
        $this->price = $price;
        $this->billingInterval = $billingInterval;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): void
    {
        $this->price = $price;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getBillingInterval(): string
    {
        return $this->billingInterval;
    }

    public function getTrialDays(): ?int
    {
        return $this->trialDays;
    }

    public function setTrialDays(?int $days): void
    {
        $this->trialDays = $days;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getGracePeriodDays(): ?int
    {
        return $this->gracePeriodDays;
    }

    public function setGracePeriodDays(?int $days): void
    {
        $this->gracePeriodDays = $days;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function setTier(string $tier): void
    {
        $this->tier = $tier;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    public function setFeatures(array $features): void
    {
        $this->features = $features;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function addFeature(string $feature, bool $enabled = true): void
    {
        $this->features[$feature] = $enabled;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function hasFeature(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    public function getUsageLimits(): ?array
    {
        return $this->usageLimits;
    }

    public function setUsageLimits(?array $limits): void
    {
        $this->usageLimits = $limits;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isAvailableNow(): bool
    {
        $now = new \DateTimeImmutable();

        if ($this->availableFrom !== null && $now < $this->availableFrom) {
            return false;
        }

        if ($this->availableUntil !== null && $now > $this->availableUntil) {
            return false;
        }

        return $this->isActive;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'billing_interval' => $this->billingInterval,
            'trial_days' => $this->trialDays,
            'grace_period_days' => $this->gracePeriodDays,
            'tier' => $this->tier,
            'features' => $this->features,
            'usage_limits' => $this->usageLimits,
            'is_active' => $this->isActive,
            'is_public' => $this->isPublic,
            'max_users' => $this->maxUsers,
            'max_storage_gb' => $this->maxStorageGb,
            'max_api_calls' => $this->maxApiCalls,
            'available_from' => $this->availableFrom?->format(\DateTimeImmutable::ATOM),
            'available_until' => $this->availableUntil?->format(\DateTimeImmutable::ATOM),
            'created_at' => $this->createdAt->format(\DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeImmutable::ATOM),
        ];
    }
}
