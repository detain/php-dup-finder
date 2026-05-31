<?php

declare(strict_types=1);

namespace App\Services\SSE;

use App\Services\AuthenticationService;
use App\Services\CacheService;
use App\Services\RateLimiter;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

class SseLiveUpdateHandler
{
    private AuthenticationService $authService;
    private CacheService $cacheService;
    private RateLimiter $rateLimiter;
    private LoopInterface $loop;
    private array $subscribers = [];
    private array $heartbeats = [];

    public function __construct(
        AuthenticationService $authService,
        CacheService $cacheService,
        RateLimiter $rateLimiter,
        LoopInterface $loop
    ) {
        $this->authService = $authService;
        $this->cacheService = $cacheService;
        $this->rateLimiter = $rateLimiter;
        $this->loop = $loop;
    }

    public function subscribe(string $sessionId, string $token, array $entityTypes = []): void
    {
        // Validate token
        $user = $this->authService->validateToken($token);

        if (!$user) {
            throw new \RuntimeException('Invalid authentication token');
        }

        // Check rate limit
        if (!$this->rateLimiter->attempt("sse:{$user['id']}", 10, 60)) {
            throw new \RuntimeException('Rate limit exceeded');
        }

        // Store subscription
        $this->subscribers[$sessionId] = [
            'id' => $sessionId,
            'user' => $user,
            'entity_types' => $entityTypes,
            'subscribed_at' => time(),
            'last_ping' => time(),
        ];

        // Start heartbeat
        $this->startHeartbeat($sessionId);

        // Send subscription confirmation
        $this->sendToSubscriber($sessionId, [
            'type' => 'subscribed',
            'session_id' => $sessionId,
            'entity_types' => $entityTypes,
            'timestamp' => time(),
        ]);
    }

    public function broadcastUpdate(string $entityType, int $entityId, string $action, array $data): void
    {
        $update = [
            'type' => 'update',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'data' => $data,
            'timestamp' => time(),
        ];

        // Send to all subscribers interested in this entity type
        foreach ($this->subscribers as $sessionId => $subscriber) {
            if (empty($subscriber['entity_types']) || in_array($entityType, $subscriber['entity_types'])) {
                $this->sendToSubscriber($sessionId, $update);
            }
        }

        // Cache for replay
        $this->cacheUpdate($entityType, $entityId, $update);
    }

    public function unsubscribe(string $sessionId): void
    {
        if (!isset($this->subscribers[$sessionId])) {
            return;
        }

        $this->sendToSubscriber($sessionId, [
            'type' => 'unsubscribed',
            'session_id' => $sessionId,
            'timestamp' => time(),
        ]);

        unset($this->subscribers[$sessionId]);

        if (isset($this->heartbeats[$sessionId])) {
            $this->heartbeats[$sessionId]->cancel();
            unset($this->heartbeats[$sessionId]);
        }
    }

    public function ping(string $sessionId): void
    {
        if (isset($this->subscribers[$sessionId])) {
            $this->subscribers[$sessionId]['last_ping'] = time();

            $this->sendToSubscriber($sessionId, [
                'type' => 'pong',
                'timestamp' => time(),
            ]);
        }
    }

    public function getActiveSubscriberCount(): int
    {
        return count($this->subscribers);
    }

    public function getSubscribersByEntityType(string $entityType): array
    {
        $subscribers = [];
        foreach ($this->subscribers as $subscriber) {
            if (empty($subscriber['entity_types']) || in_array($entityType, $subscriber['entity_types'])) {
                $subscribers[] = $subscriber;
            }
        }
        return $subscribers;
    }

    private function startHeartbeat(string $sessionId): void
    {
        $this->heartbeats[$sessionId] = $this->loop->addPeriodicTimer(
            30,
            function () use ($sessionId) {
                if (isset($this->subscribers[$sessionId])) {
                    $this->sendToSubscriber($sessionId, [
                        'type' => 'heartbeat',
                        'timestamp' => time(),
                    ]);
                }
            }
        );
    }

    private function sendToSubscriber(string $sessionId, array $event): void
    {
        $data = "event: {$event['type']}\n";
        $data .= "data: " . json_encode($event) . "\n\n";

        // In real implementation, this would write to the subscriber's response stream
        $this->writeToSubscriber($sessionId, $data);
    }

    private function writeToSubscriber(string $sessionId, string $data): void
    {
        // Implementation would write to actual subscriber connection
    }

    private function cacheUpdate(string $entityType, int $entityId, array $update): void
    {
        $key = "sse:replay:{$entityType}:{$entityId}";
        $this->cacheService->push($key, $update);
        $this->cacheService->expire($key, 3600); // 1 hour TTL
    }

    public function getCachedUpdates(string $entityType, int $entityId, int $since): array
    {
        $key = "sse:replay:{$entityType}:{$entityId}";
        $updates = $this->cacheService->getRange($key, 0, -1);

        return array_filter($updates, function ($update) use ($since) {
            return $update['timestamp'] > $since;
        });
    }
}
