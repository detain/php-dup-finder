<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\MessageRepository;
use App\Repository\ConversationRepository;
use App\Repository\UserRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class MessageCacheHandler
{
    private const CACHE_PREFIX = 'message';
    private const DEFAULT_TTL = 600;
    private const STALE_TTL = 60;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly MessageRepository $messageRepository,
        private readonly ConversationRepository $conversationRepository,
        private readonly UserRepository $userRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getMessage(int $messageId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildMessageCacheKey($messageId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'message']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'message']);
        $message = $this->messageRepository->find($messageId);

        if ($message === null) {
            return null;
        }

        $data = $this->serializeMessage($message);
        $this->setMessage($messageId, $data);
        return $data;
    }

    public function setMessage(int $messageId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildMessageCacheKey($messageId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateMessage(int $messageId): void
    {
        $cacheKey = $this->buildMessageCacheKey($messageId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateConversationMessages(int $conversationId): void
    {
        $messages = $this->messageRepository->findByConversationId($conversationId);
        $cacheKeys = array_map(
            fn($message) => $this->buildMessageCacheKey($message->getId()),
            $messages
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateConversationLastMessage($conversationId);
        $this->invalidateConversationUnreadCount($conversationId);
        $this->logger->info('Invalidated messages for conversation', [
            'conversation_id' => $conversationId,
            'message_count' => count($messages),
        ]);
    }

    public function invalidateConversationLastMessage(int $conversationId): void
    {
        $key = $this->keyBuilder->build(self::CACHE_PREFIX, 'conversation', $conversationId, 'last_message');
        $this->cache->delete($key);
    }

    public function invalidateConversationUnreadCount(int $conversationId): void
    {
        $key = $this->keyBuilder->build(self::CACHE_PREFIX, 'conversation', $conversationId, 'unread_count');
        $this->cache->delete($key);
    }

    public function refreshMessage(int $messageId): void
    {
        $cacheKey = $this->buildMessageCacheKey($messageId);
        $message = $this->messageRepository->find($messageId);

        if ($message === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeMessage($message);
        $this->setMessage($messageId, $data);
    }

    public function warmConversation(int $conversationId): void
    {
        $messages = $this->messageRepository->findRecentByConversationId($conversationId, 50);

        foreach ($messages as $message) {
            $data = $this->serializeMessage($message);
            $this->setMessage($message->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed message cache for conversation', [
            'conversation_id' => $conversationId,
            'messages_warmed' => count($messages),
        ]);
    }

    public function handleSendMessage(int $messageId): void
    {
        $message = $this->messageRepository->find($messageId);
        if ($message === null) {
            return;
        }

        $this->invalidateConversationMessages($message->getConversationId());

        $participants = $this->conversationRepository->find($message->getConversationId())?->getParticipantIds() ?? [];
        foreach ($participants as $participantId) {
            $this->invalidateUserUnreadCount($participantId);
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'send_message',
            'message_id' => (string) $messageId,
        ]);
    }

    public function handleEditMessage(int $messageId): void
    {
        $this->invalidateMessage($messageId);

        $message = $this->messageRepository->find($messageId);
        if ($message !== null) {
            $this->invalidateConversationLastMessage($message->getConversationId());
        }

        $this->logger->info('Handled edit message cache invalidation', [
            'message_id' => $messageId,
        ]);
    }

    public function handleDeleteMessage(int $messageId): void
    {
        $message = $this->messageRepository->find($messageId);
        if ($message !== null) {
            $this->invalidateMessage($messageId);
            $this->invalidateConversationMessages($message->getConversationId());
        }

        $this->logger->info('Handled delete message cache invalidation', [
            'message_id' => $messageId,
        ]);
    }

    public function handleMarkAsRead(int $conversationId, int $userId): void
    {
        $this->invalidateConversationUnreadCount($conversationId);
        $this->invalidateUserUnreadCount($userId);

        $this->logger->info('Handled mark as read cache invalidation', [
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);
    }

    public function handleBlockUser(int $blockerId, int $blockedId): void
    {
        $conversations = $this->conversationRepository->findBetweenUsers($blockerId, $blockedId);

        foreach ($conversations as $conversation) {
            $this->invalidateConversationMessages($conversation->getId());
        }

        $this->logger->info('Handled block user cache invalidation', [
            'blocker_id' => $blockerId,
            'blocked_id' => $blockedId,
        ]);
    }

    private function buildMessageCacheKey(int $messageId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'message', $messageId);
    }

    private function invalidateUserUnreadCount(int $userId): void
    {
        $key = $this->keyBuilder->build(self::CACHE_PREFIX, 'user', $userId, 'unread_count');
        $this->cache->delete($key);
    }

    private function serializeMessage(object $message): array
    {
        return [
            'id' => $message->getId(),
            'conversation_id' => $message->getConversationId(),
            'sender_id' => $message->getSenderId(),
            'content' => $message->getContent(),
            'is_edited' => $message->isEdited(),
            'created_at' => $message->getCreatedAt()?->format(\DATE_ATOM),
            'edited_at' => $message->getEditedAt()?->format(\DATE_ATOM),
        ];
    }
}
