<?php
declare(strict_types=1);

namespace App\Engagement\Handlers;

use App\Entity\Like;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\ValidationService;
use App\Service\ReputationService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ContentLikedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly ValidationService $validationService,
        private readonly ReputationService $reputationService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Like $like): void
    {
        $this->logger->info('Processing content liked event', [
            'like_id' => $like->getId(),
            'content_type' => $like->getContentType(),
            'content_id' => $like->getContentId(),
            'liker_id' => $like->getUserId(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->validateLikeAction($like);
            $this->recordLikeOnContent($like);
            $this->updateContentLikeCount($like);
            $this->updateLikerEngagementStats($like);
            $this->notifyContentOwner($like);
            $this->recordEngagementAnalytics($like);
            $this->createAuditEntry($like);
            $this->checkEngagementMilestones($like);

            $this->entityManager->commit();

            $this->logger->info('Content liked event processed', [
                'like_id' => $like->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process content liked event', [
                'like_id' => $like->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateLikeAction(Like $like): void
    {
        $existingLike = $this->entityManager
            ->getRepository(Like::class)
            ->findOneBy([
                'userId' => $like->getUserId(),
                'contentType' => $like->getContentType(),
                'contentId' => $like->getContentId(),
            ]);

        if ($existingLike !== null) {
            throw new \DomainException('User has already liked this content');
        }

        $owner = $this->getContentOwner($like);
        if ($owner !== null && $owner->getId() === $like->getUserId()) {
            throw new \DomainException('Cannot like your own content');
        }

        $this->validationService->validateEngagementAction([
            'user_id' => $like->getUserId(),
            'action' => 'like',
            'content_type' => $like->getContentType(),
            'content_id' => $like->getContentId(),
        ]);

        $this->logger->debug('Validated like action', [
            'like_id' => $like->getId(),
        ]);
    }

    private function recordLikeOnContent(Like $like): void
    {
        $like->setStatus('active');
        $like->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($like);

        $this->logger->debug('Recorded like on content', [
            'like_id' => $like->getId(),
        ]);
    }

    private function updateContentLikeCount(Like $like): void
    {
        $content = $this->findContent($like);
        if ($content === null) {
            throw new \RuntimeException('Content not found');
        }

        $currentLikes = $content->getLikeCount() ?? 0;
        $content->setLikeCount($currentLikes + 1);
        $content->setLastLikedAt(new \DateTimeImmutable());

        $this->entityManager->persist($content);

        $this->logger->debug('Updated content like count', [
            'content_id' => $like->getContentId(),
            'new_count' => $currentLikes + 1,
        ]);
    }

    private function updateLikerEngagementStats(Like $like): void
    {
        $user = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($like->getUserId());

        if ($user === null) {
            return;
        }

        $user->setTotalLikesGiven($user->getTotalLikesGiven() + 1);
        $user->setLastEngagementAt(new \DateTimeImmutable());
        $user->setLastLikeAt(new \DateTimeImmutable());

        $this->entityManager->persist($user);

        $this->logger->debug('Updated liker engagement stats', [
            'user_id' => $user->getId(),
            'total_likes_given' => $user->getTotalLikesGiven(),
        ]);
    }

    private function notifyContentOwner(Like $like): void
    {
        $owner = $this->getContentOwner($like);
        if ($owner === null) {
            return;
        }

        if ($owner->getId() === $like->getUserId()) {
            return;
        }

        $liker = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($like->getUserId());

        $notification = new \App\Entity\EngagementNotification();
        $notification->setUserId($owner->getId());
        $notification->setType('like_received');
        $notification->setTitle('Someone liked your ' . $like->getContentType());
        $notification->setBody(
            ($liker?->getDisplayName() ?? 'Someone') . ' liked your ' .
            $like->getContentType() . '. Check it out!'
        );
        $notification->setReferenceType($like->getContentType());
        $notification->setReferenceId($like->getContentId());
        $notification->setActorId($like->getUserId());
        $notification->setStatus('unread');
        $notification->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($notification);

        $this->queueService->publish('notifications.engagement', [
            'notification_id' => $notification->getId(),
            'user_id' => $owner->getId(),
            'type' => 'like_received',
            'actor_name' => $liker?->getDisplayName(),
            'content_type' => $like->getContentType(),
        ]);

        $this->logger->debug('Notified content owner', [
            'like_id' => $like->getId(),
            'owner_id' => $owner->getId(),
        ]);
    }

    private function recordEngagementAnalytics(Like $like): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('content_liked');
        $analyticsEvent->setCustomerId($like->getUserId());
        $analyticsEvent->setPayload([
            'like_id' => $like->getId(),
            'content_type' => $like->getContentType(),
            'content_id' => $like->getContentId(),
            'owner_id' => $this->getContentOwnerId($like),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded engagement analytics', [
            'like_id' => $like->getId(),
            'event' => 'content_liked',
        ]);
    }

    private function createAuditEntry(Like $like): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('CONTENT_LIKED');
        $auditEntry->setEntityType('like');
        $auditEntry->setEntityId($like->getId());
        $auditEntry->setUserId($like->getUserId());
        $auditEntry->setMetadata([
            'content_type' => $like->getContentType(),
            'content_id' => $like->getContentId(),
            'owner_id' => $this->getContentOwnerId($like),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'like_id' => $like->getId(),
            'action' => 'CONTENT_LIKED',
        ]);
    }

    private function checkEngagementMilestones(Like $like): void
    {
        $user = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($like->getUserId());

        if ($user === null) {
            return;
        }

        $milestones = [10, 50, 100, 500, 1000];
        $currentLikes = $user->getTotalLikesGiven();

        foreach ($milestones as $milestone) {
            if ($currentLikes >= $milestone && $currentLikes - 1 < $milestone) {
                $achievement = new \App\Entity\UserAchievement();
                $achievement->setUser($user);
                $achievement->setType('engagement_milestone');
                $achievement->setName("{$milestone} Likes Given");
                $achievement->setDescription("Gave {$milestone} likes to others' content");
                $achievement->setAwardedAt(new \DateTimeImmutable());
                $achievement->setCreatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($achievement);

                $this->queueService->publish('achievements.unlocked', [
                    'user_id' => $user->getId(),
                    'achievement_type' => 'engagement_milestone',
                    'achievement_name' => "{$milestone}_likes_given",
                ]);

                $this->logger->info('Awarded engagement milestone achievement', [
                    'user_id' => $user->getId(),
                    'milestone' => $milestone,
                ]);
            }
        }
    }

    private function getContentOwner(Like $like): ?\App\Entity\User
    {
        $ownerId = $this->getContentOwnerId($like);
        if ($ownerId === null) {
            return null;
        }
        return $this->entityManager->getRepository(\App\Entity\User::class)->find($ownerId);
    }

    private function getContentOwnerId(Like $like): ?int
    {
        return match ($like->getContentType()) {
            'post' => $this->entityManager->getRepository(\App\Entity\Post::class)
                ->find($like->getContentId())?->getAuthorId(),
            'comment' => $this->entityManager->getRepository(\App\Entity\Comment::class)
                ->find($like->getContentId())?->getAuthorId(),
            'photo' => $this->entityManager->getRepository(\App\Entity\Photo::class)
                ->find($like->getContentId())?->getUploaderId(),
            default => null,
        };
    }

    private function findContent(Like $like): ?object
    {
        return match ($like->getContentType()) {
            'post' => $this->entityManager->getRepository(\App\Entity\Post::class)->find($like->getContentId()),
            'comment' => $this->entityManager->getRepository(\App\Entity\Comment::class)->find($like->getContentId()),
            'photo' => $this->entityManager->getRepository(\App\Entity\Photo::class)->find($like->getContentId()),
            default => null,
        };
    }
}
