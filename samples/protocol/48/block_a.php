<?php

declare(strict_types=1);

namespace App\Services\SSE;

use App\Services\AuthenticationService;
use App\Services\CacheService;
use App\Services\RateLimiter;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

class SseNotificationHandler
{
    private AuthenticationService $authService;
    private CacheService $cacheService;
    private RateLimiter $rateLimiter;
    private LoopInterface $loop;
    private array $clients = [];

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

    public function handleConnection(string $clientId, string $token, array $channels = []): void
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

        // Store client connection
        $this->clients[$clientId] = [
            'id' => $clientId,
            'user' => $user,
            'channels' => $channels,
            'connected_at' => time(),
            'last_activity' => time(),
        ];

        // Subscribe to channels
        foreach ($channels as $channel) {
            $this->subscribeToChannel($clientId, $channel);
        }

        // Setup heartbeat
        $this->setupHeartbeat($clientId);

        // Send initial connection event
        $this->sendEvent($clientId, [
            'type' => 'connected',
            'client_id' => $clientId,
            'user_id' => $user['id'],
            'channels' => $channels,
            'timestamp' => time(),
        ]);
    }

    public function broadcastToChannel(string $channel, array $data): void
    {
        $event = [
            'type' => 'broadcast',
            'channel' => $channel,
            'data' => $data,
            'timestamp' => time(),
        ];

        foreach ($this->clients as $clientId => $client) {
            if (in_array($channel, $client['channels'])) {
                $this->sendEvent($clientId, $event);
            }
        }

        // Store in cache for reconnecting clients
        $this->cacheService->push("channel:{$channel}:events", $event);
    }

    public function sendToUser(int $userId, array $data): void
    {
        $event = [
            'type' => 'message',
            'data' => $data,
            'timestamp' => time(),
        ];

        foreach ($this->clients as $clientId => $client) {
            if ($client['user']['id'] === $userId) {
                $this->sendEvent($clientId, $event);
            }
        }
    }

    public function sendToClient(string $clientId, array $data): void
    {
        if (isset($this->clients[$clientId])) {
            $this->sendEvent($clientId, [
                'type' => 'message',
                'data' => $data,
                'timestamp' => time(),
            ]);
        }
    }

    public function disconnect(string $clientId, int $code = 1000): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $client = $this->clients[$clientId];

        // Unsubscribe from channels
        foreach ($client['channels'] as $channel) {
            $this->unsubscribeFromChannel($clientId, $channel);
        }

        // Remove client
        unset($this->clients[$clientId]);

        // Cancel heartbeat timer
        if (isset($this->heartbeats[$clientId])) {
            $this->heartbeats[$clientId]->cancel();
            unset($this->heartbeats[$clientId]);
        }
    }

    private function setupHeartbeat(string $clientId): void
    {
        $this->heartbeats[$clientId] = $this->loop->addPeriodicTimer(
            30,
            function () use ($clientId) {
                if (isset($this->clients[$clientId])) {
                    $this->sendEvent($clientId, [
                        'type' => 'heartbeat',
                        'timestamp' => time(),
                    ]);

                    $this->clients[$clientId]['last_activity'] = time();
                }
            }
        );
    }

    private function sendEvent(string $clientId, array $event): void
    {
        $data = "event: {$event['type']}\n";
        $data .= "data: " . json_encode($event) . "\n\n";

        // In real implementation, this would write to the client's response stream
        $this->writeToClient($clientId, $data);
    }

    private function writeToClient(string $clientId, string $data): void
    {
        // Implementation would write to actual client connection
    }

    private function subscribeToChannel(string $clientId, string $channel): void
    {
        $key = "channel:{$channel}:subscribers";
        $this->cacheService->sAdd($key, $clientId);
    }

    private function unsubscribeFromChannel(string $clientId, string $channel): void
    {
        $key = "channel:{$channel}:subscribers";
        $this->cacheService->sRem($key, $clientId);
    }

    public function getClientCount(): int
    {
        return count($this->clients);
    }

    public function getClientsByChannel(string $channel): array
    {
        $subscribers = [];
        foreach ($this->clients as $clientId => $client) {
            if (in_array($channel, $client['channels'])) {
                $subscribers[] = $client;
            }
        }
        return $subscribers;
    }
}
