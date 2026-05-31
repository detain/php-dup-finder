<?php
declare(strict_types=1);

namespace App\Notifications\WebSocket;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\WampServerInterface;
use Ratchet\Wamp\Topic;
use SplObjectStorage;

final class NotificationWebSocketServer implements WampServerInterface
{
    private LoggerInterface $logger;
    private SplObjectStorage $connections;
    private array $userNotifications = [];
    private int $heartbeatInterval = 30000;
    private int $maxConnections = 50000;
    private int $reconnectDelay = 5000;
    private int $notificationTTL = 86400;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->connections = new SplObjectStorage();
        
        $this->heartbeatInterval = (int)$config->get('notifications.websocket.heartbeat_interval', 30000);
        $this->maxConnections = (int)$config->get('notifications.websocket.max_connections', 50000);
        $this->reconnectDelay = (int)$config->get('notifications.websocket.reconnect_delay', 5000);
        $this->notificationTTL = (int)$config->get('notifications.websocket.notification_ttl', 86400);
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        if ($this->connections->count() >= $this->maxConnections) {
            $this->logger->warning('Notification connection rejected: max connections reached');
            $conn->close();
            return;
        }
        
        $conn->resourceId = $this->generateConnectionId();
        $conn->joinedAt = time();
        $conn->isAuthenticated = false;
        $conn->userId = null;
        
        $this->connections->attach($conn);
        
        $this->logger->info('Notification connection opened', [
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
        
        $this->logger->info('Notification connection closed', [
            'connection_id' => $conn->resourceId,
            'user_id' => $conn->userId ?? null,
            'duration' => time() - $conn->joinedAt,
        ]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->logger->error('Notification connection error', [
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
                
            case 'get_notifications':
                $this->handleGetNotifications($conn, $id, $params);
                break;
                
            case 'mark_as_read':
                $this->handleMarkAsRead($conn, $id, $params);
                break;
                
            case 'mark_all_as_read':
                $this->handleMarkAllAsRead($conn, $id, $params);
                break;
                
            case 'get_unread_count':
                $this->handleGetUnreadCount($conn, $id, $params);
                break;
                
            case 'subscribe_user':
                $this->handleSubscribeUser($conn, $id, $params);
                break;
                
            default:
                $conn->callError($id, $topic, 'Unknown method: ' . $method);
        }
    }

    public function onSubscribe(ConnectionInterface $conn, Topic $topic): void
    {
        $topicName = $topic->getId();
        
        $this->logger->info('Notification subscription created', [
            'connection_id' => $conn->resourceId,
            'topic' => $topicName,
        ]);
    }

    public function onUnubscribe(ConnectionInterface $conn, Topic $topic): void
    {
        $this->logger->info('Notification subscription removed', [
            'connection_id' => $conn->resourceId,
            'topic' => $topic->getId(),
        ]);
    }

    public function onPublish(ConnectionInterface $conn, Topic $topic, $message, array $exclude, array $eligible): void
    {
        $topicName = $topic->getId();
        
        $payload = json_decode($message, true) ?? $message;
        
        $this->logger->debug('Notification published', [
            'connection_id' => $conn->resourceId,
            'topic' => $topicName,
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
        
        $this->logger->info('Notification user authenticated', [
            'connection_id' => $conn->resourceId,
            'user_id' => $conn->userId,
        ]);
        
        $conn->send(json_encode([
            'type' => 'authenticated',
            'user_id' => $conn->userId,
        ]));
    }

    private function handleGetNotifications(ConnectionInterface $conn, $id, array $params): void
    {
        if (!$conn->isAuthenticated) {
            $conn->callError($id, new Topic('wamp'), 'Not authenticated');
            return;
        }
        
        $page = $params['page'] ?? 1;
        $perPage = $params['per_page'] ?? 20;
        
        $notifications = $this->getNotificationsForUser($conn->userId, $page, $perPage);
        
        $conn->send(json_encode([
            'type' => 'notifications_list',
            'notifications' => $notifications,
            'page' => $page,
            'per_page' => $perPage,
        ]));
    }

    private function handleMarkAsRead(ConnectionInterface $conn, $id, array $params): void
    {
        if (!$conn->isAuthenticated) {
            $conn->callError($id, new Topic('wamp'), 'Not authenticated');
            return;
        }
        
        $notificationId = $params['notification_id'] ?? null;
        
        if (empty($notificationId)) {
            $conn->callError($id, new Topic('wamp'), 'Notification ID required');
            return;
        }
        
        $this->markNotificationAsRead($conn->userId, $notificationId);
        
        $conn->send(json_encode([
            'type' => 'notification_read',
            'notification_id' => $notificationId,
        ]));
    }

    private function handleMarkAllAsRead(ConnectionInterface $conn, $id, array $params): void
    {
        if (!$conn->isAuthenticated) {
            $conn->callError($id, new Topic('wamp'), 'Not authenticated');
            return;
        }
        
        $this->markAllNotificationsAsRead($conn->userId);
        
        $conn->send(json_encode([
            'type' => 'all_notifications_read',
        ]));
    }

    private function handleGetUnreadCount(ConnectionInterface $conn, $id, array $params): void
    {
        if (!$conn->isAuthenticated) {
            $conn->callError($id, new Topic('wamp'), 'Not authenticated');
            return;
        }
        
        $count = $this->getUnreadCountForUser($conn->userId);
        
        $conn->send(json_encode([
            'type' => 'unread_count',
            'count' => $count,
        ]));
    }

    private function handleSubscribeUser(ConnectionInterface $conn, $id, array $params): void
    {
        if (!$conn->isAuthenticated) {
            $conn->callError($id, new Topic('wamp'), 'Not authenticated');
            return;
        }
        
        $userTopic = new Topic('notifications.user.' . $conn->userId);
        $userTopic->add($conn);
        
        $this->logger->info('User subscribed to personal notifications', [
            'user_id' => $conn->userId,
        ]);
    }

    private function handleUserDisconnect(ConnectionInterface $conn): void
    {
        $this->logger->info('User disconnected from notification service', [
            'user_id' => $conn->userId,
        ]);
    }

    private function validateAndDecodeToken(string $token): ?array
    {
        return ['user_id' => 123, 'exp' => time() + 3600];
    }

    private function generateConnectionId(): string
    {
        return 'notif_conn_' . bin2hex(random_bytes(8));
    }

    private function getNotificationsForUser(int $userId, int $page, int $perPage): array
    {
        return [
            ['id' => 1, 'title' => 'New Message', 'read' => false, 'created_at' => time()],
            ['id' => 2, 'title' => 'Order Shipped', 'read' => true, 'created_at' => time() - 3600],
        ];
    }

    private function markNotificationAsRead(int $userId, string $notificationId): void
    {
        $this->logger->info('Notification marked as read', [
            'user_id' => $userId,
            'notification_id' => $notificationId,
        ]);
    }

    private function markAllNotificationsAsRead(int $userId): void
    {
        $this->logger->info('All notifications marked as read', [
            'user_id' => $userId,
        ]);
    }

    private function getUnreadCountForUser(int $userId): int
    {
        return 5;
    }

    public function sendNotificationToUser(int $userId, array $notification): void
    {
        foreach ($this->connections as $conn) {
            if ($conn->isAuthenticated && $conn->userId === $userId) {
                $conn->send(json_encode([
                    'type' => 'new_notification',
                    'notification' => $notification,
                ]));
            }
        }
    }
}
