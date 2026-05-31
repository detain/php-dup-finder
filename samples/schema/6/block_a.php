<?php

declare(strict_types=1);

namespace App\Domain\FeatureFlags\Entity;

use App\Domain\FeatureFlags\ValueObject\FlagId;

/**
 * Doctrine entity for feature flags.
 * This entity is duplicated in:
 * - Database table: feature_flags
 * - Configuration files: config/feature_flags.php
 * - External feature flag service (LaunchDarkly, etc.)
 * - Percentage rollout system
 * - Admin dashboard schema
 *
 * @ORM\Entity(repositoryClass=FeatureFlagRepository::class)
 * @ORM\Table(name="feature_flags")
 * @ORM\Index(name="idx_key", columns={"flag_key"}, unique=true)
 * @ORM\Index(name="idx_environment", columns={"environment"})
 * @ORM\Index(name="idx_is_enabled", columns={"is_enabled"})
 */
class FeatureFlag
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=36)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    private string $flagKey;

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
    private string $flagType;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $isEnabled = false;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $environment = 'production';

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $targetingRules = null;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $defaultValue = null;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $percentageRollout = null;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $userSegments = null;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $metadata = null;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $updatedAt;

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $createdBy = null;

    public function __construct(
        string $flagKey,
        string $name,
        string $flagType,
        string $environment = 'production'
    ) {
        $this->id = FlagId::generate()->toString();
        $this->flagKey = $flagKey;
        $this->name = $name;
        $this->flagType = $flagType;
        $this->environment = $environment;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFlagKey(): string
    {
        return $this->flagKey;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFlagType(): string
    {
        return $this->flagType;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function enable(): void
    {
        $this->isEnabled = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function disable(): void
    {
        $this->isEnabled = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setTargetingRules(array $rules): void
    {
        $this->targetingRules = $rules;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getTargetingRules(): ?array
    {
        return $this->targetingRules;
    }

    public function setPercentageRollout(?int $percentage): void
    {
        if ($percentage < 0 || $percentage > 100) {
            throw new \InvalidArgumentException('Percentage must be between 0 and 100');
        }
        $this->percentageRollout = $percentage;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPercentageRollout(): ?int
    {
        return $this->percentageRollout;
    }

    public function evaluateForUser(array $userContext): bool
    {
        if (!$this->isEnabled) {
            return false;
        }

        if ($this->percentageRollout !== null) {
            $hash = crc32($userContext['id'] ?? '') % 100;
            if ($hash >= $this->percentageRollout) {
                return false;
            }
        }

        if ($this->targetingRules !== null) {
            return $this->evaluateTargetingRules($userContext);
        }

        return true;
    }

    private function evaluateTargetingRules(array $userContext): bool
    {
        foreach ($this->targetingRules as $rule) {
            if ($this->evaluateRule($rule, $userContext)) {
                return $rule['value'] ?? true;
            }
        }

        return $this->defaultValue['enabled'] ?? false;
    }

    private function evaluateRule(array $rule, array $userContext): bool
    {
        $attribute = $rule['attribute'] ?? '';
        $operator = $rule['operator'] ?? 'eq';
        $value = $rule['value'] ?? null;

        $userValue = $userContext[$attribute] ?? null;

        return match ($operator) {
            'eq' => $userValue === $value,
            'neq' => $userValue !== $value,
            'in' => in_array($userValue, $value ?? [], true),
            'not_in' => !in_array($userValue, $value ?? [], true),
            'gt' => $userValue > $value,
            'gte' => $userValue >= $value,
            'lt' => $userValue < $value,
            'lte' => $userValue <= $value,
            default => false,
        };
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'flag_key' => $this->flagKey,
            'name' => $this->name,
            'description' => $this->description,
            'flag_type' => $this->flagType,
            'is_enabled' => $this->isEnabled,
            'environment' => $this->environment,
            'targeting_rules' => $this->targetingRules,
            'default_value' => $this->defaultValue,
            'percentage_rollout' => $this->percentageRollout,
            'user_segments' => $this->userSegments,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt->format(\DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeImmutable::ATOM),
            'created_by' => $this->createdBy,
        ];
    }
}
