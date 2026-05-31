<?php
declare(strict_types=1);

namespace App\Domain\Review\EventHandler;

use App\Entity\Review;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\ModerationService;
use App\Service\ReputationService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ReviewSubmittedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly ModerationService $moderationService,
        private readonly ReputationService $reputationService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Review $review): void
    {
        $this->logger->info('Processing review submitted event', [
            'review_id' => $review->getId(),
            'product_id' => $review->getProductId(),
            'author_id' => $review->getAuthorId(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->validateReviewContent($review);
            $this->runContentModeration($review);
            $this->updateProductRating($review);
            $this->updateAuthorReputation($review);
            $this->notifyProductOwner($review);
            $this->recordReviewAnalytics($review);
            $this->createAuditEntry($review);
            $this->triggerIncentiveActions($review);

            $this->entityManager->commit();

            $this->logger->info('Review submitted event processed', [
                'review_id' => $review->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process review submitted event', [
                'review_id' => $review->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateReviewContent(Review $review): void
    {
        if (empty(trim($review->getContent()))) {
            throw new \DomainException('Review content cannot be empty');
        }

        if (strlen($review->getContent()) < 20) {
            throw new \DomainException('Review content is too short (minimum 20 characters)');
        }

        if (strlen($review->getContent()) > 5000) {
            throw new \DomainException('Review content exceeds maximum length (5000 characters)');
        }

        $existingReview = $this->entityManager
            ->getRepository(Review::class)
            ->findOneBy([
                'authorId' => $review->getAuthorId(),
                'productId' => $review->getProductId(),
                'status' => ['approved', 'pending'],
            ]);

        if ($existingReview !== null) {
            throw new \DomainException('You have already reviewed this product');
        }

        $this->logger->debug('Validated review content', [
            'review_id' => $review->getId(),
            'content_length' => strlen($review->getContent()),
        ]);
    }

    private function runContentModeration(Review $review): void
    {
        $moderationResult = $this->moderationService->moderate([
            'text' => $review->getContent(),
            'author_id' => $review->getAuthorId(),
            'product_id' => $review->getProductId(),
        ]);

        $review->setModerationScore($moderationResult->getScore());
        $review->setFlaggedWords($moderationResult->getFlaggedWords());
        $review->setStatus($moderationResult->isApproved() ? 'pending' : 'under_review');
        $review->setModerationNotes($moderationResult->getNotes());
        $review->setModerationRunAt(new \DateTimeImmutable());

        $this->entityManager->persist($review);

        if (!$moderationResult->isApproved()) {
            $this->queueService->publish('moderation.manual_review', [
                'review_id' => $review->getId(),
                'score' => $moderationResult->getScore(),
                'flagged_words' => $moderationResult->getFlaggedWords(),
                'priority' => 'normal',
            ]);
        }

        $this->logger->debug('Ran content moderation', [
            'review_id' => $review->getId(),
            'approved' => $moderationResult->isApproved(),
            'score' => $moderationResult->getScore(),
        ]);
    }

    private function updateProductRating(Review $review): void
    {
        $product = $this->entityManager
            ->getRepository(\App\Entity\Product::class)
            ->find($review->getProductId());

        if ($product === null) {
            throw new \RuntimeException('Product not found');
        }

        $approvedReviews = $this->entityManager
            ->getRepository(Review::class)
            ->findApprovedByProduct($product->getId());

        $totalRating = 0;
        $reviewCount = 0;
        foreach ($approvedReviews as $existingReview) {
            $totalRating += $existingReview->getRating();
            $reviewCount++;
        }

        $newAverage = $reviewCount > 0 ? $totalRating / $reviewCount : 0;

        $product->setRatingAverage($newAverage);
        $product->setReviewCount($reviewCount);
        $product->setLastReviewAt(new \DateTimeImmutable());

        $this->entityManager->persist($product);

        $this->logger->debug('Updated product rating', [
            'product_id' => $product->getId(),
            'new_average' => $newAverage,
            'review_count' => $reviewCount,
        ]);
    }

    private function updateAuthorReputation(Review $review): void
    {
        $author = $this->entityManager
            ->getRepository(\App\Entity\Author::class)
            ->find($review->getAuthorId());

        if ($author === null) {
            return;
        }

        $totalReviews = $this->entityManager
            ->getRepository(Review::class)
            ->countByAuthor($author->getId());

        $helpfulVotes = $this->entityManager
            ->getRepository(\App\Entity\ReviewVote::class)
            ->countHelpfulByAuthor($author->getId());

        $reputation = $this->reputationService->calculateReputationScore(
            $totalReviews,
            $helpfulVotes,
            $author->getAccountAge()
        );

        $author->setReputationScore($reputation);
        $author->setTotalReviews($totalReviews);
        $author->setHelpfulVotes($helpfulVotes);
        $author->setLastReviewAt(new \DateTimeImmutable());

        $this->entityManager->persist($author);

        $this->logger->debug('Updated author reputation', [
            'author_id' => $author->getId(),
            'reputation_score' => $reputation,
        ]);
    }

    private function notifyProductOwner(Review $review): void
    {
        $product = $this->entityManager
            ->getRepository(\App\Entity\Product::class)
            ->find($review->getProductId());

        if ($product === null) {
            return;
        }

        $owner = $this->entityManager
            ->getRepository(\App\Entity\Seller::class)
            ->find($product->getSellerId());

        if ($owner === null) {
            return;
        }

        $notification = new \App\Entity\SellerNotification();
        $notification->setSeller($owner);
        $notification->setType('new_review');
        $notification->setTitle('New Review on ' . $product->getName());
        $notification->setBody(sprintf(
            'A %d-star review was submitted for your product. Rating: %d/5',
            $review->getRating(),
            $review->getRating()
        ));
        $notification->setMetadata([
            'review_id' => $review->getId(),
            'product_id' => $product->getId(),
            'rating' => $review->getRating(),
        ]);
        $notification->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($notification);

        $this->queueService->publish('notifications.seller', [
            'seller_id' => $owner->getId(),
            'type' => 'new_review',
            'review_id' => $review->getId(),
            'product_name' => $product->getName(),
            'rating' => $review->getRating(),
        ]);

        $this->logger->debug('Notified product owner', [
            'review_id' => $review->getId(),
            'owner_id' => $owner->getId(),
        ]);
    }

    private function recordReviewAnalytics(Review $review): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('review_submitted');
        $analyticsEvent->setCustomerId($review->getAuthorId());
        $analyticsEvent->setPayload([
            'review_id' => $review->getId(),
            'product_id' => $review->getProductId(),
            'rating' => $review->getRating(),
            'verified_purchase' => $review->isVerifiedPurchase(),
            'moderation_score' => $review->getModerationScore(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded review analytics', [
            'review_id' => $review->getId(),
            'event' => 'review_submitted',
        ]);
    }

    private function createAuditEntry(Review $review): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('REVIEW_SUBMITTED');
        $auditEntry->setEntityType('review');
        $auditEntry->setEntityId($review->getId());
        $auditEntry->setUserId($review->getAuthorId());
        $auditEntry->setMetadata([
            'product_id' => $review->getProductId(),
            'rating' => $review->getRating(),
            'verified_purchase' => $review->isVerifiedPurchase(),
            'status' => $review->getStatus(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'review_id' => $review->getId(),
            'action' => 'REVIEW_SUBMITTED',
        ]);
    }

    private function triggerIncentiveActions(Review $review): void
    {
        $author = $this->entityManager
            ->getRepository(\App\Entity\Author::class)
            ->find($review->getAuthorId());

        $reviewMilestones = [1, 10, 50, 100, 500];

        foreach ($reviewMilestones as $milestone) {
            $previousCount = $this->entityManager
                ->getRepository(Review::class)
                ->countByAuthor($author->getId()) - 1;

            if ($previousCount < $milestone && $author->getTotalReviews() >= $milestone) {
                $incentive = new \App\Entity\AuthorIncentive();
                $incentive->setAuthor($author);
                $incentive->setType('review_badge');
                $incentive->setDescription("{$milestone} Reviews Badge");
                $incentive->setMilestone($milestone);
                $incentive->setAwardedAt(new \DateTimeImmutable());
                $incentive->setCreatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($incentive);

                $this->queueService->publish('author.badge_awarded', [
                    'author_id' => $author->getId(),
                    'badge' => "{$milestone}_reviews",
                    'incentive_id' => $incentive->getId(),
                ]);

                $this->logger->info('Awarded review milestone badge', [
                    'author_id' => $author->getId(),
                    'milestone' => $milestone,
                ]);
            }
        }

        if ($review->getRating() >= 4 && $review->isVerifiedPurchase()) {
            $points = $this->entityManager
                ->getRepository(\App\Entity\PointType::class)
                ->findOneBy(['code' => 'review_creation']);

            if ($points !== null) {
                $this->entityManager->persist(new \App\Entity\PointTransaction([
                    'author_id' => $author->getId(),
                    'point_type_id' => $points->getId(),
                    'points' => $points->getValue(),
                    'description' => 'Points for submitting a review',
                    'created_at' => new \DateTimeImmutable(),
                ]));
            }
        }
    }
}
