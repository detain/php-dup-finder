<?php
declare(strict_types=1);

namespace App\WebSocket;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\WampServerInterface;
use Ratchet\Wamp\Topic;
use SplObjectStorage;

abstract class AbstractWebSocketServer implements WampServerInterface
{
    protected LoggerInterface $logger;
    protected SplObjectStorage $connections;
    protected int $heartbeatInterval = 30000;
    protected int $maxConnections = 10000;
    
    abstract protected function getServiceName(): string;
    abstract protected function getConfigPrefix(): string;

    public function __construct(ConfigManager $config, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->connections = new SplObjectStorage();
        
        $prefix = $this->getConfigPrefix();
        $this->heartbeatInterval = (int)$config->get($prefix . '.heartbeat_interval', 30000);
        $this->maxConnections = (int)$config->get($prefix . '.max_connections', 10000);
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        if ($this->connections->count() >= $this->maxConnections) {
            $this->logger->warning($this->getServiceName() . ' connection rejected: max connections');
            $conn->close();
            return;
        }
        
        $this->initializeConnection($conn);
        $this->connections->attach($conn);
        
        $this->logger->info($this->getServiceName() . ' connection opened', [
            'connection_id' => $conn->resourceId,
            'total_connections' => $this->connections->count(),
        ]);
        
        $this->sendConnectionEstablished($conn);
    }

    protected function initializeConnection(ConnectionInterface $conn): void
    {
        $conn->resourceId = $this->generateConnectionId();
        $conn->joinedAt = time();
        $conn->isAuthenticated = false;
        $conn->userId = null;
    }

    protected function sendConnectionEstablished(ConnectionInterface $conn): void
    {
        $conn->send(json_encode([
            'type' => 'connection_established',
            'connection_id' => $conn->resourceId,
            'heartbeat_interval' => $this->heartbeatInterval,
        ]));
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->handleDisconnect($conn);
        $this->connections->detach($conn);
        
        $this->logger->info($this->getServiceName() . ' connection closed', [
            'connection_id' => $conn->resourceId,
            'user_id' => $conn->userId ?? null,
            'duration' => time() - $conn->joinedAt,
        ]);
    }

    protected function handleDisconnect(ConnectionInterface $conn): void
    {
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->logger->error($this->getServiceName() . ' connection error', [
            'connection_id' => $conn->resourceId,
            'error' => $e->getMessage(),
        ]);
        
        $conn->close();
    }

    public function onSubscribe(ConnectionInterface $conn, Topic $topic): void
    {
        $this->logger->debug($this->getServiceName() . ' subscription created', [
            'connection_id' => $conn->resourceId,
            'topic' => $topic->getId(),
        ]);
    }

    public function onUnubscribe(ConnectionInterface $conn, Topic $topic): void
    {
        $this->logger->debug($this->getServiceName() . ' subscription removed', [
            'connection_id' => $conn->resourceId,
            'topic' => $topic->getId(),
        ]);
    }

    public function onPublish(ConnectionInterface $conn, Topic $topic, $message, array $exclude, array $eligible): void
    {
        $topic->broadcast($message, $exclude, $eligible);
    }

    protected function handleAuthenticate(ConnectionInterface $conn, $id, Topic $topic, array $params): void
    {
        $token = $params['token'] ?? null;
        
        if (empty($token)) {
            $conn->callError($id, $topic, 'Authentication token required');
            return;
        }
        
        $userData = $this->validateAndDecodeToken($token);
        
        if (!$userData) {
            $conn->callError($id, $topic, 'Invalid authentication token');
            return;
        }
        
        $conn->isAuthenticated = true;
        $conn->userId = $userData['user_id'];
        $conn->userData = $userData;
        
        $this->logger->info($this->getServiceName() . ' user authenticated', [
            'connection_id' => $conn->resourceId,
            'user_id' => $conn->userId,
        ]);
        
        $conn->send(json_encode([
            'type' => 'authenticated',
            'user_id' => $conn->userId,
        ]));
    }

    protected function validateAndDecodeToken(string $token): ?array
    {
        return ['user_id' => 123, 'exp' => time() + 3600];
    }

    protected function generateConnectionId(): string
    {
        return $this->getServiceName() . '_conn_' . bin2hex(random_bytes(8));
    }

    protected function requireAuthentication(ConnectionInterface $conn, $id, Topic $topic): bool
    {
        if (!$conn->isAuthenticated) {
            $conn->callError($id, $topic, 'Not authenticated');
            return false;
        }
        return true;
    }
}
