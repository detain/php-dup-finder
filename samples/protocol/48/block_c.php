<?php

declare(strict_types=1);

namespace App\Services\SSE;

use React\EventLoop\LoopInterface;

trait SseHandlerTrait
{
    protected AuthenticationService $authService;
    protected CacheService $cacheService;
    protected RateLimiter $rateLimiter;
    protected LoopInterface $loop;
    protected array $clients = [];
    protected array $heartbeats = [];

    protected function authenticateSseConnection(string $token): ?array
    {
        $user = $this->authService->validateToken($token);

        if (!$user) {
            return null;
        }

        if (!$this->rateLimiter->attempt("sse:{$user['id']}", 10, 60)) {
            return null;
        }

        return $user;
    }

    protected function registerClient(string $clientId, array $user, array $channels = []): void
    {
        $this->clients[$clientId] = [
            'id' => $clientId,
            'user' => $user,
            'channels' => $channels,
            'connected_at' => time(),
            'last_activity' => time(),
        ];

        $this->setupHeartbeat($clientId);
    }

    protected function removeClient(string $clientId): void
    {
        if (isset($this->heartbeats[$clientId])) {
            $this->heartbeats[$clientId]->cancel();
            unset($this->heartbeats[$clientId]);
        }

        unset($this->clients[$clientId]);
    }

    protected function setupHeartbeat(string $clientId, int $interval = 30): void
    {
        $this->heartbeats[$clientId] = $this->loop->addPeriodicTimer(
            $interval,
            function () use ($clientId) {
                if (isset($this->clients[$clientId])) {
                    $this->sendSseEvent($clientId, [
                        'type' => 'heartbeat',
                        'timestamp' => time(),
                    ]);
                }
            }
        );
    }

    protected function sendSseEvent(string $clientId, array $event): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $data = "event: {$event['type']}\n";
        $data .= "data: " . json_encode($event) . "\n\n";

        $this->writeToClient($clientId, $data);
        $this->clients[$clientId]['last_activity'] = time();
    }

    protected function broadcastToChannel(string $channel, array $data): void
    {
        $event = [
            'type' => 'broadcast',
            'channel' => $channel,
            'data' => $data,
            'timestamp' => time(),
        ];

        foreach ($this->clients as $clientId => $client) {
            if (in_array($channel, $client['channels'])) {
                $this->sendSseEvent($clientId, $event);
            }
        }
    }

    protected function sendToUser(int $userId, array $data): void
    {
        $event = [
            'type' => 'message',
            'data' => $data,
            'timestamp' => time(),
        ];

        foreach ($this->clients as $clientId => $client) {
            if ($client['user']['id'] === $userId) {
                $this->sendSseEvent($clientId, $event);
            }
        }
    }

    abstract protected function writeToClient(string $clientId, string $data): void;
}

class SseNotificationHandler
{
    use SseHandlerTrait;

    public function handleConnection(string $clientId, string $token, array $channels = []): void
    {
        $user = $this->authenticateSseConnection($token);

        if (!$user) {
            throw new \RuntimeException('Invalid token');
        }

        $this->registerClient($clientId, $user, $channels);

        $this->sendSseEvent($clientId, [
            'type' => 'connected',
            'client_id' => $clientId,
            'channels' => $channels,
        ]);
    }
}
