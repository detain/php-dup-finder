<?php
declare(strict_types=1);

namespace App\Collaboration\Handlers;

use App\Entity\Share;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\ValidationService;
use App\Service\PermissionService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ContentSharedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly ValidationService $validationService,
        private readonly PermissionService $permissionService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Share $share): void
    {
        $this->logger->info('Processing content shared event', [
            'share_id' => $share->getId(),
            'content_type' => $share->getContentType(),
            'content_id' => $share->getContentId(),
            'sharer_id' => $share->getSharerId(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->validateSharePermissions($share);
            $this->recordShareOnContent($share);
            $this->grantAccessToRecipient($share);
            $this->notifyRecipients($share);
            $this->updateSharerStats($share);
            $this->recordShareAnalytics($share);
            $this->createAuditEntry($share);
            $this->triggerReferralTracking($share);

            $this->entityManager->commit();

            $this->logger->info('Content shared event processed', [
                'share_id' => $share->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process content shared event', [
                'share_id' => $share->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateSharePermissions(Share $share): void
    {
        $content = $this->findContent($share);
        if ($content === null) {
            throw new \RuntimeException('Content not found');
        }

        $ownerId = $this->getContentOwnerId($share);

        if ($ownerId !== $share->getSharerId()) {
            $canShare = $this->permissionService->canShare($share->getSharerId(), $content);
            if (!$canShare) {
                throw new \DomainException('You do not have permission to share this content');
            }
        }

        if (!empty($share->getRecipientIds())) {
            $maxRecipients = $this->entityManager
                ->getRepository(\App\Entity\SystemSetting::class)
                ->findOneBy(['key' => 'max_share_recipients'])?->getValue() ?? 50;

            if (count($share->getRecipientIds()) > $maxRecipients) {
                throw new \DomainException("Cannot share with more than {$maxRecipients} recipients");
            }
        }

        $this->logger->debug('Validated share permissions', [
            'share_id' => $share->getId(),
        ]);
    }

    private function recordShareOnContent(Share $share): void
    {
        $share->setStatus('active');
        $share->setSharedAt(new \DateTimeImmutable());

        $content = $this->findContent($share);
        if ($content !== null) {
            $content->setShareCount($content->getShareCount() + 1);
            $content->setLastSharedAt(new \DateTimeImmutable());
            $this->entityManager->persist($content);
        }

        $this->entityManager->persist($share);

        $this->logger->debug('Recorded share on content', [
            'share_id' => $share->getId(),
        ]);
    }

    private function grantAccessToRecipient(Share $share): void
    {
        foreach ($share->getRecipientIds() as $recipientId) {
            $existingAccess = $this->entityManager
                ->getRepository(\App\Entity\ContentAccess::class)
                ->findOneBy([
                    'userId' => $recipientId,
                    'contentType' => $share->getContentType(),
                    'contentId' => $share->getContentId(),
                ]);

            if ($existingAccess !== null) {
                continue;
            }

            $access = new \App\Entity\ContentAccess();
            $access->setUserId($recipientId);
            $access->setContentType($share->getContentType());
            $access->setContentId($share->getContentId());
            $access->setSharedBy($share->getSharerId());
            $access->setPermissionLevel($share->getPermissionLevel());
            $access->setExpiresAt($share->getExpiresAt());
            $access->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($access);

            $this->logger->debug('Granted content access to recipient', [
                'share_id' => $share->getId(),
                'recipient_id' => $recipientId,
            ]);
        }
    }

    private function notifyRecipients(Share $share): void
    {
        $sharer = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($share->getSharerId());

        $content = $this->findContent($share);

        foreach ($share->getRecipientIds() as $recipientId) {
            $recipient = $this->entityManager
                ->getRepository(\App\Entity\User::class)
                ->find($recipientId);

            if ($recipient === null) {
                continue;
            }

            $notification = new \App\Entity\ShareNotification();
            $notification->setUserId($recipientId);
            $notification->setType('content_shared');
            $notification->setTitle(($sharer?->getDisplayName() ?? 'Someone') . ' shared ' . $share->getContentType() . ' with you');
            $notification->setBody($this->generateShareMessage($share, $content));
            $notification->setReferenceType($share->getContentType());
            $notification->setReferenceId($share->getContentId());
            $notification->setActorId($share->getSharerId());
            $notification->setStatus('unread');
            $notification->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($notification);

            $this->queueService->publish('notifications.share', [
                'notification_id' => $notification->getId(),
                'user_id' => $recipientId,
                'sharer_name' => $sharer?->getDisplayName(),
                'content_type' => $share->getContentType(),
            ]);
        }

        $this->logger->debug('Notified share recipients', [
            'share_id' => $share->getId(),
            'recipient_count' => count($share->getRecipientIds()),
        ]);
    }

    private function updateSharerStats(Share $share): void
    {
        $user = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($share->getSharerId());

        if ($user === null) {
            return;
        }

        $user->setTotalShares($user->getTotalShares() + 1);
        $user->setLastShareAt(new \DateTimeImmutable());

        $this->entityManager->persist($user);

        $this->logger->debug('Updated sharer stats', [
            'user_id' => $user->getId(),
            'total_shares' => $user->getTotalShares(),
        ]);
    }

    private function recordShareAnalytics(Share $share): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('content_shared');
        $analyticsEvent->setCustomerId($share->getSharerId());
        $analyticsEvent->setPayload([
            'share_id' => $share->getId(),
            'content_type' => $share->getContentType(),
            'content_id' => $share->getContentId(),
            'recipient_count' => count($share->getRecipientIds()),
            'share_method' => $share->getMethod(),
            'permission_level' => $share->getPermissionLevel(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded share analytics', [
            'share_id' => $share->getId(),
            'event' => 'content_shared',
        ]);
    }

    private function createAuditEntry(Share $share): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('CONTENT_SHARED');
        $auditEntry->setEntityType('share');
        $auditEntry->setEntityId($share->getId());
        $auditEntry->setUserId($share->getSharerId());
        $auditEntry->setMetadata([
            'content_type' => $share->getContentType(),
            'content_id' => $share->getContentId(),
            'recipient_count' => count($share->getRecipientIds()),
            'permission_level' => $share->getPermissionLevel(),
            'expires_at' => $share->getExpiresAt()?->format(\DATE_ATOM),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'share_id' => $share->getId(),
            'action' => 'CONTENT_SHARED',
        ]);
    }

    private function triggerReferralTracking(Share $share): void
    {
        if ($share->getMethod() === 'link' && $share->getReferralCode() !== null) {
            $this->queueService->publish('referral.share_click', [
                'share_id' => $share->getId(),
                'referral_code' => $share->getReferralCode(),
                'content_type' => $share->getContentType(),
                'content_id' => $share->getContentId(),
                'sharer_id' => $share->getSharerId(),
                'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ]);
        }

        $this->logger->debug('Triggered referral tracking', [
            'share_id' => $share->getId(),
        ]);
    }

    private function findContent(Share $share): ?object
    {
        return match ($share->getContentType()) {
            'post' => $this->entityManager->getRepository(\App\Entity\Post::class)->find($share->getContentId()),
            'folder' => $this->entityManager->getRepository(\App\Entity\Folder::class)->find($share->getContentId()),
            'document' => $this->entityManager->getRepository(\App\Entity\Document::class)->find($share->getContentId()),
            default => null,
        };
    }

    private function getContentOwnerId(Share $share): ?int
    {
        return match ($share->getContentType()) {
            'post' => $this->entityManager->getRepository(\App\Entity\Post::class)->find($share->getContentId())?->getAuthorId(),
            'folder' => $this->entityManager->getRepository(\App\Entity\Folder::class)->find($share->getContentId())?->getOwnerId(),
            'document' => $this->entityManager->getRepository(\App\Entity\Document::class)->find($share->getContentId())?->getOwnerId(),
            default => null,
        };
    }

    private function generateShareMessage(Share $share, ?object $content): string
    {
        $contentName = match ($share->getContentType()) {
            'post' => $content instanceof \App\Entity\Post ? $content->getTitle() : 'a post',
            'folder' => $content instanceof \App\Entity\Folder ? $content->getName() : 'a folder',
            'document' => $content instanceof \App\Entity\Document ? $content->getTitle() : 'a document',
            default => 'content',
        };

        $message = "You have been granted access to {$contentName}.";

        if ($share->getMessage() !== null) {
            $message .= " Message: " . $share->getMessage();
        }

        return $message;
    }
}
