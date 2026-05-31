<?php

declare(strict_types=1);

namespace App\WebSocket;

use App\Services\AuthenticationService;
use App\Services\RateLimiter;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use App\Events\WebSocket\MessageReceived;
use App\Events\WebSocket\ClientConnected;
use App\Events\WebSocket\ClientDisconnected;

class WebSocketHandler implements MessageComponentInterface
{
    private $connections = [];
    private AuthenticationService $authService;
    private RateLimiter $rateLimiter;

    public function __construct(
        AuthenticationService $authService,
        RateLimiter $rateLimiter
    ) {
        $this->authService = $authService;
        $this->rateLimiter = $rateLimiter;
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        // Authenticate connection
        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $queryParams);

        $token = $queryParams['token'] ?? null;

        if (!$token) {
            $conn->close(4001);
            return;
        }

        $user = $this->authService->validateWebSocketToken($token);
        if (!$user) {
            $conn->close(4002);
            return;
        }

        // Store connection with user context
        $this->connections[$conn->resourceId] = [
            'connection' => $conn,
            'user' => $user,
            'authenticated_at' => time(),
            'last_activity' => time(),
        ];

        // Notify application
        event(new ClientConnected($user, $conn->resourceId));

        $conn->send(json_encode([
            'type' => 'connected',
            'user_id' => $user['id'],
            'session_id' => $conn->resourceId,
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $connData = $this->connections[$from->resourceId] ?? null;

        if (!$connData) {
            return;
        }

        // Update activity
        $connData['last_activity'] = time();

        // Rate limit check
        if (!$this->rateLimiter->attempt("ws:{$connData['user']['id']}", 60, 60)) {
            $from->send(json_encode([
                'type' => 'error',
                'code' => 429,
                'message' => 'Rate limit exceeded',
            ]));
            return;
        }

        // Parse message
        $data = json_decode($msg, true);

        if (!$data || !isset($data['type'])) {
            $from->send(json_encode([
                'type' => 'error',
                'code' => 400,
                'message' => 'Invalid message format',
            ]));
            return;
        }

        // Handle message types
        switch ($data['type']) {
            case 'ping':
                $from->send(json_encode(['type' => 'pong', 'timestamp' => time()]));
                break;

            case 'subscribe':
                $this->handleSubscribe($from, $connData, $data);
                break;

            case 'unsubscribe':
                $this->handleUnsubscribe($from, $connData, $data);
                break;

            case 'message':
                $this->handleChatMessage($from, $connData, $data);
                break;

            default:
                $from->send(json_encode([
                    'type' => 'error',
                    'code' => 400,
                    'message' => 'Unknown message type',
                ]));
        }

        // Emit event
        event(new MessageReceived($connData['user'], $data));
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $connData = $this->connections[$conn->resourceId] ?? null;

        if ($connData) {
            event(new ClientDisconnected($connData['user'], $conn->resourceId));
        }

        unset($this->connections[$conn->resourceId]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $connData = $this->connections[$conn->resourceId] ?? null;

        if ($connData) {
            error_log("WebSocket error for user {$connData['user']['id']}: " . $e->getMessage());
        }

        $conn->close();
    }

    private function handleSubscribe(ConnectionInterface $conn, array $connData, array $data): void
    {
        if (empty($data['channel'])) {
            $conn->send(json_encode([
                'type' => 'error',
                'code' => 400,
                'message' => 'Channel name required',
            ]));
            return;
        }

        // Check authorization for channel
        if (!$this->authService->canAccessChannel($connData['user'], $data['channel'])) {
            $conn->send(json_encode([
                'type' => 'error',
                'code' => 403,
                'message' => 'Not authorized for this channel',
            ]));
            return;
        }

        $conn->send(json_encode([
            'type' => 'subscribed',
            'channel' => $data['channel'],
        ]));
    }

    private function handleUnsubscribe(ConnectionInterface $conn, array $connData, array $data): void
    {
        if (empty($data['channel'])) {
            return;
        }

        $conn->send(json_encode([
            'type' => 'unsubscribed',
            'channel' => $data['channel'],
        ]));
    }

    private function handleChatMessage(ConnectionInterface $conn, array $connData, array $data): void
    {
        if (empty($data['content']) || empty($data['recipient_id'])) {
            $conn->send(json_encode([
                'type' => 'error',
                'code' => 400,
                'message' => 'Content and recipient required',
            ]));
            return;
        }

        // Validate message length
        if (strlen($data['content']) > 5000) {
            $conn->send(json_encode([
                'type' => 'error',
                'code' => 400,
                'message' => 'Message too long (max 5000 characters)',
            ]));
            return;
        }

        // Send to recipient
        $this->sendToUser($data['recipient_id'], [
            'type' => 'message',
            'from' => $connData['user']['id'],
            'content' => $data['content'],
            'timestamp' => time(),
        ]);

        $conn->send(json_encode([
            'type' => 'message_sent',
            'recipient_id' => $data['recipient_id'],
            'timestamp' => time(),
        ]));
    }

    private function sendToUser(int $userId, array $data): void
    {
        foreach ($this->connections as $connData) {
            if ($connData['user']['id'] === $userId) {
                $connData['connection']->send(json_encode($data));
            }
        }
    }
}
