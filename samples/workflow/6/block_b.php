<?php
declare(strict_types=1);

namespace App\Fraud\Review;

use App\Domain\Entity\Review;
use App\Domain\Entity\FraudCheck;
use App\Domain\Repository\ReviewRepositoryInterface;
use App\Domain\Service\FraudAnalysisServiceInterface;
use App\Domain\Service\UserServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class ManualReviewWorkflow
{
    public function __construct(
        private ReviewRepositoryInterface $reviewRepository,
        private FraudAnalysisServiceInterface $fraudAnalysis,
        private UserServiceInterface $userService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function processReview(string $reviewId): void
    {
        $review = $this->reviewRepository->findById($reviewId);
        if ($review === null) {
            throw new \RuntimeException("Review not found: {$reviewId}");
        }

        $this->logger->info('Starting manual review workflow', ['review_id' => $reviewId]);

        $this->assignToReviewer($review);

        $this->gatherEvidence($review);

        $this->performInternalChecks($review);

        $this->calculateReviewScore($review);

        $decision = $this->makeReviewDecision($review);

        $this->recordDecision($review, $decision);

        $this->updateRelatedEntities($review);

        $this->notifyParties($review, $decision);

        $this->logger->info('Manual review workflow completed', [
            'review_id' => $reviewId,
            'decision' => $decision,
        ]);
    }

    private function assignToReviewer(Review $review): void
    {
        $fraudTeam = $this->userService->getAvailableFraudReviewers();
        if (count($fraudTeam) === 0) {
            throw new \RuntimeException("No fraud reviewers available");
        }

        $reviewer = $fraudTeam[array_rand($fraudTeam)];
        $review->setAssignedTo($reviewer->getId());
        $review->setAssignedAt(new \DateTimeImmutable());
        $review->setStatus('in_review');

        $this->reviewRepository->save($review);

        $this->notificationService->send(
            $reviewer->getId(),
            'review_assigned',
            [
                'review_id' => $review->getId()->toString(),
                'priority' => $review->getPriority(),
            ]
        );

        $this->logger->debug('Review assigned', [
            'review_id' => $review->getId()->toString(),
            'reviewer_id' => $reviewer->getId()->toString(),
        ]);
    }

    private function gatherEvidence(Review $review): void
    {
        $evidence = $this->fraudAnalysis->gatherEvidence($review->getFraudCheck());

        $review->setEvidence($evidence);
        $this->reviewRepository->save($review);

        $this->logger->debug('Evidence gathered', [
            'review_id' => $review->getId()->toString(),
            'evidence_count' => count($evidence),
        ]);
    }

    private function performInternalChecks(Review $review): void
    {
        $checks = $this->fraudAnalysis->performInternalChecks($review->getFraudCheck());

        $review->setInternalChecks($checks);
        $this->reviewRepository->save($review);

        $this->logger->debug('Internal checks performed', [
            'review_id' => $review->getId()->toString(),
            'checks_count' => count($checks),
        ]);
    }

    private function calculateReviewScore(Review $review): void
    {
        $fraudCheck = $review->getFraudCheck();
        $evidence = $review->getEvidence();
        $checks = $review->getInternalChecks();

        $score = $fraudCheck->getRiskScore();
        foreach ($checks as $check) {
            if ($check['passed'] === false) {
                $score += 20;
            }
        }

        if ($evidence['is_new_customer'] ?? false) {
            $score += 10;
        }

        if (($evidence['order_amount'] ?? 0) > 500) {
            $score += 15;
        }

        $review->setReviewScore($score);
        $this->reviewRepository->save($review);

        $this->logger->debug('Review score calculated', [
            'review_id' => $review->getId()->toString(),
            'score' => $score,
        ]);
    }

    private function makeReviewDecision(Review $review): string
    {
        $score = $review->getReviewScore();
        $checks = $review->getInternalChecks();

        $failedChecks = count(array_filter($checks, fn($c) => $c['passed'] === false));

        if ($score >= 80 || $failedChecks >= 3) {
            return 'reject';
        }

        if ($score >= 50 || $failedChecks >= 2) {
            return 'escalate';
        }

        if ($score >= 30 || $failedChecks >= 1) {
            return 'approve_with_conditions';
        }

        return 'approve';
    }

    private function recordDecision(Review $review, string $decision): void
    {
        $review->setDecision($decision);
        $review->setStatus('decided');
        $review->setDecidedAt(new \DateTimeImmutable());

        $this->reviewRepository->save($review);
    }

    private function updateRelatedEntities(Review $review): void
    {
        $fraudCheck = $review->getFraudCheck();
        $fraudCheck->setManualReviewDecision($review->getDecision());
        $fraudCheck->setManualReviewScore($review->getReviewScore());

        $this->fraudAnalysis->updateFraudCheck($fraudCheck);

        $this->logger->debug('Related entities updated', ['review_id' => $review->getId()->toString()]);
    }

    private function notifyParties(Review $review, string $decision): void
    {
        if ($decision === 'reject') {
            $this->notificationService->sendToCustomer(
                $review->getFraudCheck()->getOrderId(),
                'order_rejected_fraud'
            );
        }

        $this->notificationService->send(
            $review->getAssignedTo(),
            'review_completed',
            [
                'review_id' => $review->getId()->toString(),
                'decision' => $decision,
            ]
        );
    }
}
