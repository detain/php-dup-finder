<?php

declare(strict_types=1);

namespace App\Insurance;

use App\Entity\InsurancePolicy;
use App\Repository\PolicyRepository;
use App\Service\ClaimLogger;
use Psr\Log\LoggerInterface;

final class InsuranceClaimService
{
    public function __construct(
        private readonly PolicyRepository $policyRepository,
        private readonly ClaimLogger $claimLogger,
        private readonly LoggerInterface $logger,
    ) {}

    public function fileClaim(int $policyId, int $claimAmount, string $description): array
    {
        $policy = $this->policyRepository->findById($policyId);

        if ($policy === null) {
            throw new \RuntimeException('Policy not found');
        }

        if ($claimAmount <= 0) {
            throw new \InvalidArgumentException('Claim amount must be positive');
        }

        if ($claimAmount > 1000000) {
            throw new \InvalidArgumentException('Single claims cannot exceed 1,000,000');
        }

        if ($claimAmount > 100000 && !$policy->isVerified()) {
            throw new \InvalidArgumentException('Claims over 100,000 require verified policy');
        }

        if ($claimAmount > 50000 && $policy->getRiskScore() > 70) {
            throw new \InvalidArgumentException('High-risk policies have reduced claim limits');
        }

        if ($policy->isLocked()) {
            throw new \InvalidArgumentException('Policy is locked');
        }

        if ($policy->isSuspended()) {
            throw new \InvalidArgumentException('Policy is suspended');
        }

        if ($policy->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Policy must be active to file claims');
        }

        if ($policy->getCoverageAmount() < $claimAmount) {
            throw new \InvalidArgumentException('Claim amount exceeds policy coverage');
        }

        if ($policy->getRemainingBenefit() < $claimAmount) {
            throw new \InvalidArgumentException('Claim amount exceeds remaining benefit');
        }

        if ($policy->getAnnualClaimCount() >= 3) {
            throw new \InvalidArgumentException('Annual claim limit exceeded');
        }

        if ($policy->getAnnualClaimTotal() + $claimAmount > 500000) {
            throw new \InvalidArgumentException('Annual claim value limit exceeded');
        }

        $claim = $this->createClaim($policy, $claimAmount, $description);

        $this->logger->info('Claim filed', [
            'claim_id' => $claim['id'],
            'policy_id' => $policyId,
            'amount' => $claimAmount,
        ]);

        return $claim;
    }

    public function approveClaim(int $claimId): bool
    {
        $claim = $this->claimLogger->findClaim($claimId);

        if ($claim === null) {
            throw new \RuntimeException('Claim not found');
        }

        if ($claim->getStatus() !== 'pending') {
            throw new \InvalidArgumentException('Only pending claims can be approved');
        }

        if ($claim->getAmount() > 500000 && !$claim->isVerified()) {
            throw new \InvalidArgumentException('Claims over 500,000 require additional verification');
        }

        $policy = $this->policyRepository->findById($claim->getPolicyId());

        if ($policy === null) {
            throw new \RuntimeException('Policy not found');
        }

        if ($policy->getRemainingBenefit() < $claim->getAmount()) {
            throw new \InvalidArgumentException('Claim exceeds remaining policy benefit');
        }

        $claim->setStatus('approved');
        $claim->setApprovedAt(new \DateTimeImmutable());

        $policy->setRemainingBenefit($policy->getRemainingBenefit() - $claim->getAmount());
        $policy->setAnnualClaimCount($policy->getAnnualClaimCount() + 1);
        $policy->setAnnualClaimTotal($policy->getAnnualClaimTotal() + $claim->getAmount());

        $this->claimLogger->save($claim);
        $this->policyRepository->save($policy);

        $this->logger->info('Claim approved', [
            'claim_id' => $claimId,
        ]);

        return true;
    }

    private function createClaim(InsurancePolicy $policy, int $amount, string $description): array
    {
        $claimId = uniqid('claim_');

        return [
            'id' => $claimId,
            'policy_id' => $policy->getId(),
            'amount' => $amount,
            'description' => $description,
            'status' => 'pending',
            'filed_at' => new \DateTimeImmutable(),
        ];
    }
}
