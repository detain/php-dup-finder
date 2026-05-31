<?php

declare(strict_types=1);

namespace App\WebSocket;

use Ratchet\ConnectionInterface;

trait WebSocketHandlerTrait
{
    protected array $connections = [];
    protected AuthenticationService $authService;
    protected RateLimiter $rateLimiter;

    protected function authenticateConnection(ConnectionInterface $conn): ?array
    {
        $queryParams = [];
        parse_str($conn->httpRequest->getUri()->getQuery(), $queryParams);

        $token = $queryParams['token'] ?? null;
        if (!$token) {
            return null;
        }

        return $this->authService->validateWebSocketToken($token);
    }

    protected function registerConnection(ConnectionInterface $conn, array $user): void
    {
        $this->connections[$conn->resourceId] = [
            'connection' => $conn,
            'user' => $user,
            'authenticated_at' => time(),
            'last_activity' => time(),
        ];
    }

    protected function updateActivity(string $resourceId): void
    {
        if (isset($this->connections[$resourceId])) {
            $this->connections[$resourceId]['last_activity'] = time();
        }
    }

    protected function checkRateLimit(string $resourceId, int $maxPerMinute = 60): bool
    {
        $connData = $this->connections[$resourceId] ?? null;
        if (!$connData) {
            return false;
        }

        return $this->rateLimiter->attempt(
            "ws:{$connData['user']['id']}",
            $maxPerMinute,
            60
        );
    }

    protected function parseMessage(string $msg): ?array
    {
        $data = json_decode($msg, true);

        if (!$data || !isset($data['type'])) {
            return null;
        }

        return $data;
    }

    protected function sendError(ConnectionInterface $conn, int $code, string $message): void
    {
        $conn->send(json_encode([
            'type' => 'error',
            'code' => $code,
            'message' => $message,
        ]));
    }

    protected function getConnectionUser(string $resourceId): ?array
    {
        return $this->connections[$resourceId]['user'] ?? null;
    }

    protected function removeConnection(string $resourceId): void
    {
        unset($this->connections[$resourceId]);
    }
}

class WebSocketHandler implements MessageComponentInterface
{
    use WebSocketHandlerTrait;

    public function onOpen(ConnectionInterface $conn): void
    {
        $user = $this->authenticateConnection($conn);

        if (!$user) {
            $conn->close(4002);
            return;
        }

        $this->registerConnection($conn, $user);

        $conn->send(json_encode([
            'type' => 'connected',
            'user_id' => $user['id'],
        ]));
    }
}
