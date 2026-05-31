<?php
declare(strict_types=1);

namespace Salesforce\CRM\Service;

use Salesforce\CRM\Repository\OpportunityRepository;
use Salesforce\CRM\Repository\ContractRepository;
use Salesforce\CRM\Entity\Opportunity;
use Salesforce\CRM\Entity\Contract;
use Salesforce\CRM\Entity\Quote;
use Salesforce\CRM\Exception\OpportunityStageException;
use Salesforce\CRM\Service\PricingEngine;
use Salesforce\CRM\Service\DiscountApprovalService;
use Psr\Log\LoggerInterface;
use Salesforce\CRM\Event\OpportunityClosedWonEvent;
use Salesforce\CRM\Event\OpportunityClosedLostEvent;

final class OpportunityLifecycleService
{
    private OpportunityRepository $opportunityRepo;
    private ContractRepository $contractRepo;
    private PricingEngine $pricingEngine;
    private DiscountApprovalService $discountService;
    private LoggerInterface $logger;

    public function __construct(
        OpportunityRepository $opportunityRepo,
        ContractRepository $contractRepo,
        PricingEngine $pricingEngine,
        DiscountApprovalService $discountService,
        LoggerInterface $logger
    ) {
        $this->opportunityRepo = $opportunityRepo;
        $this->contractRepo = $contractRepo;
        $this->pricingEngine = $pricingEngine;
        $this->discountService = $discountService;
        $this->logger = $logger;
    }

    public function closeWon(string $opportunityId, array $closingDetails): CloseWonResult
    {
        $this->logger->info('Processing Close Won for opportunity', [
            'opportunity_id' => $opportunityId
        ]);

        $opportunity = $this->opportunityRepo->findById($opportunityId);
        if ($opportunity === null) {
            throw new \InvalidArgumentException("Opportunity not found: {$opportunityId}");
        }

        $currentStage = $opportunity->getStage();
        if ($currentStage === 'Closed Won') {
            $this->logger->warning('Opportunity already closed won', [
                'opportunity_id' => $opportunityId
            ]);
            throw new OpportunityStageException('Opportunity is already Closed Won');
        }

        if (!$this->isValidStageTransition($currentStage, 'Closed Won')) {
            throw new OpportunityStageException(
                "Invalid stage transition from '{$currentStage}' to 'Closed Won'"
            );
        }

        $this->opportunityRepo->updateStage($opportunityId, 'Closed Won', [
            'close_date' => $closingDetails['close_date'] ?? (new \DateTimeImmutable())->format('Y-m-d'),
            'closed_won_at' => new \DateTimeImmutable()
        ]);
        $this->logger->debug('Opportunity stage updated to Closed Won', [
            'opportunity_id' => $opportunityId
        ]);

        try {
            $finalPricing = $this->pricingEngine->calculateFinalPrice(
                $opportunityId,
                $closingDetails['discounts'] ?? []
            );

            $contract = Contract::create([
                'opportunity_id' => $opportunityId,
                'account_id' => $opportunity->getAccountId(),
                'total_amount' => $finalPricing->getTotal(),
                'currency' => $opportunity->getCurrency(),
                'status' => 'draft',
                'effective_date' => $closingDetails['contract_start_date'] ?? new \DateTimeImmutable(),
                'created_at' => new \DateTimeImmutable()
            ]);

            $savedContract = $this->contractRepo->save($contract);

            $this->opportunityRepo->attachContract($opportunityId, $savedContract->getId());

            $this->logger->info('Contract created for closed opportunity', [
                'opportunity_id' => $opportunityId,
                'contract_id' => $savedContract->getId(),
                'total_amount' => $finalPricing->getTotal()
            ]);

            return new CloseWonResult([
                'success' => true,
                'opportunity_id' => $opportunityId,
                'contract_id' => $savedContract->getId(),
                'final_amount' => $finalPricing->getTotal()
            ]);

        } catch (\Throwable $e) {
            $this->opportunityRepo->updateStage($opportunityId, $currentStage);
            $this->logger->error('Close Won failed, stage reverted', [
                'opportunity_id' => $opportunityId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function closeLost(string $opportunityId, array $lossDetails): CloseLostResult
    {
        $opportunity = $this->opportunityRepo->findById($opportunityId);
        if ($opportunity === null) {
            throw new \InvalidArgumentException("Opportunity not found: {$opportunityId}");
        }

        $this->opportunityRepo->updateStage($opportunityId, 'Closed Lost', [
            'close_date' => $lossDetails['close_date'] ?? (new \DateTimeImmutable())->format('Y-m-d'),
            'closed_lost_at' => new \DateTimeImmutable(),
            'loss_reason' => $lossDetails['reason'] ?? 'unknown',
            'competitor' => $lossDetails['competitor'] ?? null
        ]);

        $this->logger->info('Opportunity marked as Closed Lost', [
            'opportunity_id' => $opportunityId,
            'reason' => $lossDetails['reason'] ?? 'unknown'
        ]);

        return new CloseLostResult([
            'success' => true,
            'opportunity_id' => $opportunityId,
            'closed_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }

    private function isValidStageTransition(string $from, string $to): bool
    {
        $validTransitions = [
            'Prospecting' => ['Qualification', 'Closed Lost'],
            'Qualification' => ['Needs Analysis', 'Proposal', 'Closed Lost'],
            'Needs Analysis' => ['Value Proposition', 'Closed Lost'],
            'Value Proposition' => ['Proposal/Price Quote', 'Closed Lost'],
            'Proposal/Price Quote' => ['Negotiation', 'Closed Won', 'Closed Lost'],
            'Negotiation' => ['Closed Won', 'Closed Lost'],
        ];

        return in_array($to, $validTransitions[$from] ?? [], true);
    }
}
