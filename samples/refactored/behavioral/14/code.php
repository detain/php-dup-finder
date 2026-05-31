<?php

declare(strict_types=1);

namespace App\Service\Discount;

use App\Entity\Person;
use Psr\Log\LoggerInterface;
use DateTimeImmutable;

final class DiscountQualificationService
{
    /** @var array<string, array{check: callable(Person): bool, discount: array{type: string, percent: float, reason: string}}> */
    private array $rules = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->initializeRules();
    }

    private function initializeRules(): void
    {
        $this->rules['loyalty'] = [
            'check' => fn(Person $p) => $p instanceof \App\Entity\Customer
                && $p->isPremiumMember()
                && $p->getLoyaltyPoints() >= 1000,
            'discount' => ['type' => 'loyalty', 'percent' => 15.0, 'reason' => 'Premium member with 1000+ points'],
        ];

        $this->rules['tier_benefit'] = [
            'check' => fn(Person $p) => $p instanceof \App\Entity\Member
                && $p->isPlatinumTier()
                && $p->getPointsBalance() >= 5000,
            'discount' => ['type' => 'tier_benefit', 'percent' => 20.0, 'reason' => 'Platinum tier with 5000+ points'],
        ];

        $this->rules['annual_commitment'] = [
            'check' => fn(Person $p) => $p instanceof \App\Entity\Subscriber
                && $p->isAnnualPlan()
                && $p->getTenureMonths() >= 12,
            'discount' => ['type' => 'annual_commitment', 'percent' => 30.0, 'reason' => 'Annual plan with 12+ months'],
        ];

        $this->rules['new_customer'] = [
            'check' => fn(Person $p) => $this->isWithinDays($p->getRegistrationDate() ?? $p->getSignupDate() ?? $p->getEnrollmentDate(), 30),
            'discount' => ['type' => 'new_customer', 'percent' => 5.0, 'reason' => 'Customer within 30 days'],
        ];

        $this->rules['anniversary'] = [
            'check' => fn(Person $p) => $this->isAnniversaryMonth($p->getRegistrationDate() ?? $p->getEnrollmentDate() ?? $p->getSignupDate()),
            'discount' => ['type' => 'anniversary', 'percent' => 20.0, 'reason' => 'Anniversary month'],
        ];
    }

    public function evaluate(Person $person): array
    {
        $applicableDiscounts = [];

        foreach ($this->rules as $key => $rule) {
            if ($rule['check']($person)) {
                $applicableDiscounts[] = $rule['discount'];
                $this->logger->info('Discount rule matched', [
                    'person_id' => $person->getId(),
                    'rule' => $key,
                    'type' => $rule['discount']['type'],
                ]);
            }
        }

        return $applicableDiscounts;
    }

    private function isWithinDays(?DateTimeImmutable $date, int $days): bool
    {
        if ($date === null) {
            return false;
        }
        return $date > new DateTimeImmutable("-{$days} days");
    }

    private function isAnniversaryMonth(?DateTimeImmutable $enrollmentDate): bool
    {
        if ($enrollmentDate === null) {
            return false;
        }
        $today = new DateTimeImmutable();
        $yearsMember = (int) $today->diff($enrollmentDate)->y;
        if ($yearsMember < 1) {
            return false;
        }
        $monthDiff = (int) $today->diff($enrollmentDate)->m;
        return $monthDiff === 0;
    }
}
