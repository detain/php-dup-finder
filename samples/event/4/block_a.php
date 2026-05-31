<?php
declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Entity\Message;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\ValidationService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class MessagePublishedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly ValidationService $validationService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Message $message): void
    {
        $this->logger->info('Processing message published event', [
            'message_id' => $message->getId(),
            'channel_id' => $message->getChannelId(),
            'author_id' => $message->getAuthorId(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->validateMessageContent($message);
            $this->persistMessageMetadata($message);
            $this->indexMessageForSearch($message);
            $this->notifyChannelSubscribers($message);
            $this->checkContentPolicy($message);
            $this->recordMessageAnalytics($message);
            $this->createAuditEntry($message);
            $this->updateAuthorStats($message);

            $this->entityManager->commit();

            $this->logger->info('Message published event processed', [
                'message_id' => $message->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process message published event', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateMessageContent(Message $message): void
    {
        if (empty(trim($message->getContent()))) {
            throw new \DomainException('Message content cannot be empty');
        }

        if (strlen($message->getContent()) > 10000) {
            throw new \DomainException('Message content exceeds maximum length');
        }

        $this->validationService->validateContent([
            'text' => $message->getContent(),
            'author_id' => $message->getAuthorId(),
            'channel_id' => $message->getChannelId(),
        ]);

        $this->logger->debug('Validated message content', [
            'message_id' => $message->getId(),
        ]);
    }

    private function persistMessageMetadata(Message $message): void
    {
        $message->setStatus('published');
        $message->setPublishedAt(new \DateTimeImmutable());

        $wordCount = str_word_count($message->getContent());
        $message->setMetadata(array_merge($message->getMetadata(), [
            'word_count' => $wordCount,
            'character_count' => strlen($message->getContent()),
            'processed_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]));

        $this->entityManager->persist($message);

        $this->logger->debug('Persisted message metadata', [
            'message_id' => $message->getId(),
            'word_count' => $wordCount,
        ]);
    }

    private function indexMessageForSearch(Message $message): void
    {
        $this->queueService->publish('search.indexing', [
            'entity_type' => 'message',
            'entity_id' => $message->getId(),
            'content' => $message->getContent(),
            'author_id' => $message->getAuthorId(),
            'channel_id' => $message->getChannelId(),
            'timestamp' => $message->getPublishedAt()->format(\DATE_ATOM),
            'priority' => 'normal',
        ]);

        $this->logger->debug('Queued message for search indexing', [
            'message_id' => $message->getId(),
        ]);
    }

    private function notifyChannelSubscribers(Message $message): void
    {
        $channel = $this->entityManager
            ->getRepository(\App\Entity\Channel::class)
            ->find($message->getChannelId());

        if ($channel === null) {
            return;
        }

        $subscribers = $this->entityManager
            ->getRepository(\App\Entity\ChannelSubscription::class)
            ->findActiveByChannel($channel->getId());

        $author = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($message->getAuthorId());

        foreach ($subscribers as $subscription) {
            if ($subscription->getUserId() === $message->getAuthorId()) {
                continue;
            }

            $notification = new \App\Entity\ChannelNotification();
            $notification->setUserId($subscription->getUserId());
            $notification->setChannelId($channel->getId());
            $notification->setType('new_message');
            $notification->setTitle('New message in ' . $channel->getName());
            $notification->setBody(
                ($author?->getDisplayName() ?? 'Someone') . ': ' .
                substr($message->getContent(), 0, 100)
            );
            $notification->setReferenceType('message');
            $notification->setReferenceId($message->getId());
            $notification->setStatus('pending');
            $notification->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($notification);

            $this->queueService->publish('notifications.channel', [
                'notification_id' => $notification->getId(),
                'user_id' => $subscription->getUserId(),
                'channel_id' => $channel->getId(),
                'message_preview' => substr($message->getContent(), 0, 100),
            ]);
        }

        $this->logger->debug('Notified channel subscribers', [
            'message_id' => $message->getId(),
            'notification_count' => count($subscribers),
        ]);
    }

    private function checkContentPolicy(Message $message): void
    {
        $policyViolations = $this->validationService->checkPolicyViolations(
            $message->getContent()
        );

        if (!empty($policyViolations)) {
            $message->setPolicyFlags($policyViolations);
            $message->setStatus('under_review');

            $this->entityManager->persist($message);

            $this->queueService->publish('moderation.content_review', [
                'message_id' => $message->getId(),
                'violations' => $policyViolations,
                'priority' => 'high',
            ]);

            $this->logger->warning('Message flagged for policy violations', [
                'message_id' => $message->getId(),
                'violations' => $policyViolations,
            ]);
        }
    }

    private function recordMessageAnalytics(Message $message): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('message_published');
        $analyticsEvent->setCustomerId($message->getAuthorId());
        $analyticsEvent->setPayload([
            'message_id' => $message->getId(),
            'channel_id' => $message->getChannelId(),
            'word_count' => str_word_count($message->getContent()),
            'has_attachments' => !empty($message->getAttachments()),
            'is_reply' => $message->getParentId() !== null,
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded message analytics', [
            'message_id' => $message->getId(),
            'event' => 'message_published',
        ]);
    }

    private function createAuditEntry(Message $message): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('MESSAGE_PUBLISHED');
        $auditEntry->setEntityType('message');
        $auditEntry->setEntityId($message->getId());
        $auditEntry->setUserId($message->getAuthorId());
        $auditEntry->setMetadata([
            'channel_id' => $message->getChannelId(),
            'parent_id' => $message->getParentId(),
            'content_length' => strlen($message->getContent()),
            'has_attachments' => !empty($message->getAttachments()),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'message_id' => $message->getId(),
            'action' => 'MESSAGE_PUBLISHED',
        ]);
    }

    private function updateAuthorStats(Message $message): void
    {
        $author = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($message->getAuthorId());

        if ($author === null) {
            return;
        }

        $author->setMessageCount($author->getMessageCount() + 1);
        $author->setLastMessageAt(new \DateTimeImmutable());

        if ($author->getFirstMessageAt() === null) {
            $author->setFirstMessageAt(new \DateTimeImmutable());
        }

        $this->entityManager->persist($author);

        $this->logger->debug('Updated author stats', [
            'author_id' => $author->getId(),
            'total_messages' => $author->getMessageCount(),
        ]);
    }
}
