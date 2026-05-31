<?php
declare(strict_types=1);

namespace App\Chat\WebSocket;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\WampServerInterface;
use Ratchet\Wamp\Topic;
use SplObjectStorage;

final class ChatWebSocketServer implements WampServerInterface
{
    private LoggerInterface $logger;
    private SplObjectStorage $connections;
    private array $subscribedTopics = [];
    private array $userConnections = [];
    private int $heartbeatInterval = 30000;
    private int $maxConnections = 10000;
    private int $reconnectDelay = 5000;
    private int $messageQueueLimit = 100;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->connections = new SplObjectStorage();
        
        $this->heartbeatInterval = (int)$config->get('chat.websocket.heartbeat_interval', 30000);
        $this->maxConnections = (int)$config->get('chat.websocket.max_connections', 10000);
        $this->reconnectDelay = (int)$config->get('chat.websocket.reconnect_delay', 5000);
        $this->messageQueueLimit = (int)$config->get('chat.websocket.message_queue_limit', 100);
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        if ($this->connections->count() >= $this->maxConnections) {
            $this->logger->warning('Chat connection rejected: max connections reached');
            $conn->close();
            return;
        }
        
        $conn->resourceId = $this->generateConnectionId();
        $conn->joinedAt = time();
        $conn->isAuthenticated = false;
        $conn->userId = null;
        $conn->subscriptions = [];
        
        $this->connections->attach($conn);
        
        $this->logger->info('Chat connection opened', [
            'connection_id' => $conn->resourceId,
            'total_connections' => $this->connections->count(),
        ]);
        
        $conn->send(json_encode([
            'type' => 'connection_established',
            'connection_id' => $conn->resourceId,
            'heartbeat_interval' => $this->heartbeatInterval,
        ]));
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if (isset($conn->userId)) {
            $this->handleUserDisconnect($conn);
        }
        
        $this->connections->detach($conn);
        
        $this->logger->info('Chat connection closed', [
            'connection_id' => $conn->resourceId,
            'user_id' => $conn->userId ?? null,
            'duration' => time() - $conn->joinedAt,
        ]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->logger->error('Chat connection error', [
            'connection_id' => $conn->resourceId,
            'error' => $e->getMessage(),
        ]);
        
        if ($conn->isAuthenticated) {
            $this->handleUserDisconnect($conn);
        }
        
        $conn->close();
    }

    public function onCall(ConnectionInterface $conn, $id, Topic $topic, array $params): void
    {
        $method = $params[0] ?? null;
        
        switch ($method) {
            case 'authenticate':
                $this->handleAuthenticate($conn, $id, $params);
                break;
                
            case 'join_channel':
                $this->handleJoinChannel($conn, $id, $topic, $params);
                break;
                
            case 'leave_channel':
                $this->handleLeaveChannel($conn, $id, $topic, $params);
                break;
                
            case 'send_message':
                $this->handleSendMessage($conn, $id, $topic, $params);
                break;
                
            case 'get_channels':
                $this->handleGetChannels($conn, $id, $params);
                break;
                
            case 'get_presence':
                $this->handleGetPresence($conn, $id, $params);
                break;
                
            default:
                $conn->callError($id, $topic, 'Unknown method: ' . $method);
        }
    }

    public function onSubscribe(ConnectionInterface $conn, Topic $topic): void
    {
        $topicName = $topic->getId();
        
        if (!isset($this->subscribedTopics[$topicName])) {
            $this->subscribedTopics[$topicName] = new SplObjectStorage();
        }
        
        $this->subscribedTopics[$topicName]->attach($conn);
        $conn->subscriptions[] = $topicName;
        
        $this->logger->info('Chat subscription created', [
            'connection_id' => $conn->resourceId,
            'topic' => $topicName,
        ]);
    }

    public function onUnubscribe(ConnectionInterface $conn, Topic $topic): void
    {
        $topicName = $topic->getId();
        
        if (isset($this->subscribedTopics[$topicName])) {
            $this->subscribedTopics[$topicName]->detach($conn);
        }
        
        $conn->subscriptions = array_filter($conn->subscriptions, fn($t) => $t !== $topicName);
        
        $this->logger->info('Chat subscription removed', [
            'connection_id' => $conn->resourceId,
            'topic' => $topicName,
        ]);
    }

    public function onPublish(ConnectionInterface $conn, Topic $topic, $message, array $exclude, array $eligible): void
    {
        $topicName = $topic->getId();
        
        $payload = json_decode($message, true) ?? $message;
        
        $this->logger->debug('Chat message published', [
            'connection_id' => $conn->resourceId,
            'topic' => $topicName,
            'payload_type' => gettype($payload),
        ]);
        
        $topic->broadcast($message, $exclude, $eligible);
    }

    private function handleAuthenticate(ConnectionInterface $conn, $id, array $params): void
    {
        $token = $params['token'] ?? null;
        
        if (empty($token)) {
            $conn->callError($id, new Topic('wamp'), 'Authentication token required');
            return;
        }
        
        $userData = $this->validateAndDecodeToken($token);
        
        if (!$userData) {
            $conn->callError($id, new Topic('wamp'), 'Invalid authentication token');
            return;
        }
        
        $conn->isAuthenticated = true;
        $conn->userId = $userData['user_id'];
        $conn->userData = $userData;
        
        if (!isset($this->userConnections[$conn->userId])) {
            $this->userConnections[$conn->userId] = [];
        }
        $this->userConnections[$conn->userId][] = $conn;
        
        $this->logger->info('Chat user authenticated', [
            'connection_id' => $conn->resourceId,
            'user_id' => $conn->userId,
        ]);
        
        $conn->send(json_encode([
            'type' => 'authenticated',
            'user_id' => $conn->userId,
            'expires_at' => $userData['exp'] ?? null,
        ]));
    }

    private function handleJoinChannel(ConnectionInterface $conn, $id, Topic $topic, array $params): void
    {
        if (!$conn->isAuthenticated) {
            $conn->callError($id, $topic, 'Not authenticated');
            return;
        }
        
        $channelId = $params['channel_id'] ?? null;
        
        if (empty($channelId)) {
            $conn->callError($id, $topic, 'Channel ID required');
            return;
        }
        
        $channelTopic = new Topic('chat.channel.' . $channelId);
        $channelTopic->add($conn);
        
        $this->logger->info('User joined chat channel', [
            'user_id' => $conn->userId,
            'channel_id' => $channelId,
        ]);
        
        $channelTopic->broadcast(json_encode([
            'type' => 'user_joined',
            'user_id' => $conn->userId,
            'channel_id' => $channelId,
            'timestamp' => time(),
        ]), [$conn], []);
    }

    private function handleLeaveChannel(ConnectionInterface $conn, $id, Topic $topic, array $params): void
    {
        if (!$conn->isAuthenticated) {
            $conn->callError($id, $topic, 'Not authenticated');
            return;
        }
        
        $channelId = $params['channel_id'] ?? null;
        
        $this->logger->info('User left chat channel', [
            'user_id' => $conn->userId,
            'channel_id' => $channelId,
        ]);
    }

    private function handleSendMessage(ConnectionInterface $conn, $id, Topic $topic, array $params): void
    {
        if (!$conn->isAuthenticated) {
            $conn->callError($id, $topic, 'Not authenticated');
            return;
        }
        
        $channelId = $params['channel_id'] ?? null;
        $content = $params['content'] ?? null;
        $replyTo = $params['reply_to'] ?? null;
        
        if (empty($channelId) || empty($content)) {
            $conn->callError($id, $topic, 'Channel ID and content required');
            return;
        }
        
        $messageData = [
            'type' => 'message',
            'channel_id' => $channelId,
            'user_id' => $conn->userId,
            'content' => $content,
            'reply_to' => $replyTo,
            'timestamp' => time(),
            'message_id' => $this->generateMessageId(),
        ];
        
        $channelTopic = new Topic('chat.channel.' . $channelId);
        $channelTopic->broadcast(json_encode($messageData), [], []);
    }

    private function handleGetChannels(ConnectionInterface $conn, $id, array $params): void
    {
        $channels = [
            ['id' => 'general', 'name' => 'General', 'member_count' => 150],
            ['id' => 'random', 'name' => 'Random', 'member_count' => 89],
            ['id' => 'tech', 'name' => 'Tech Talk', 'member_count' => 45],
        ];
        
        $conn->send(json_encode([
            'type' => 'channels_list',
            'channels' => $channels,
        ]));
    }

    private function handleGetPresence(ConnectionInterface $conn, $id, array $params): void
    {
        $channelId = $params['channel_id'] ?? null;
        
        if (empty($channelId)) {
            $conn->callError($id, new Topic('wamp'), 'Channel ID required');
            return;
        }
        
        $presence = [
            'online' => [['user_id' => '1', 'name' => 'User 1']],
            'offline' => [['user_id' => '2', 'name' => 'User 2']],
        ];
        
        $conn->send(json_encode([
            'type' => 'presence',
            'channel_id' => $channelId,
            'presence' => $presence,
        ]));
    }

    private function handleUserDisconnect(ConnectionInterface $conn): void
    {
        $userId = $conn->userId;
        
        if (!isset($this->userConnections[$userId])) {
            return;
        }
        
        $this->userConnections[$userId] = array_filter(
            $this->userConnections[$userId],
            fn($c) => $c !== $conn
        );
        
        if (empty($this->userConnections[$userId])) {
            unset($this->userConnections[$userId]);
        }
        
        foreach ($conn->subscriptions as $topicName) {
            if (isset($this->subscribedTopics[$topicName])) {
                $this->subscribedTopics[$topicName]->detach($conn);
            }
        }
    }

    private function validateAndDecodeToken(string $token): ?array
    {
        return ['user_id' => 123, 'exp' => time() + 3600];
    }

    private function generateConnectionId(): string
    {
        return 'conn_' . bin2hex(random_bytes(8));
    }

    private function generateMessageId(): string
    {
        return 'msg_' . bin2hex(random_bytes(8));
    }
}
