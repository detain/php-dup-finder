<?php
declare(strict_types=1);

namespace App\Collaboration\WebSocket;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\WampServerInterface;
use Ratchet\Wamp\Topic;
use SplObjectStorage;

final class CollaborationWebSocketServer implements WampServerInterface
{
    private LoggerInterface $logger;
    private SplObjectStorage $connections;
    private array $documentSessions = [];
    private array $cursorPositions = [];
    private int $heartbeatInterval = 30000;
    private int $maxConnections = 5000;
    private int $operationBufferDelay = 50;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->connections = new SplObjectStorage();
        
        $this->heartbeatInterval = (int)$config->get('collaboration.websocket.heartbeat_interval', 30000);
        $this->maxConnections = (int)$config->get('collaboration.websocket.max_connections', 5000);
        $this->operationBufferDelay = (int)$config->get('collaboration.websocket.operation_buffer_delay', 50);
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        if ($this->connections->count() >= $this->maxConnections) {
            $this->logger->warning('Collaboration connection rejected: max connections reached');
            $conn->close();
            return;
        }
        
        $conn->resourceId = $this->generateConnectionId();
        $conn->joinedAt = time();
        $conn->isAuthenticated = false;
        $conn->userId = null;
        $conn->documentId = null;
        
        $this->connections->attach($conn);
        
        $this->logger->info('Collaboration connection opened', [
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
        if ($conn->documentId) {
            $this->handleLeaveDocument($conn);
        }
        
        if (isset($conn->userId)) {
            $this->handleUserDisconnect($conn);
        }
        
        $this->connections->detach($conn);
        
        $this->logger->info('Collaboration connection closed', [
            'connection_id' => $conn->resourceId,
            'duration' => time() - $conn->joinedAt,
        ]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->logger->error('Collaboration connection error', [
            'connection_id' => $conn->resourceId,
            'error' => $e->getMessage(),
        ]);
        
        if ($conn->documentId) {
            $this->handleLeaveDocument($conn);
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
                
            case 'join_document':
                $this->handleJoinDocument($conn, $id, $params);
                break;
                
            case 'leave_document':
                $this->handleLeaveDocumentRequest($conn, $id, $params);
                break;
                
            case 'operation':
                $this->handleOperation($conn, $id, $params);
                break;
                
            case 'cursor_update':
                $this->handleCursorUpdate($conn, $id, $params);
                break;
                
            case 'awareness_update':
                $this->handleAwarenessUpdate($conn, $id, $params);
                break;
                
            case 'get_collaborators':
                $this->handleGetCollaborators($conn, $id, $params);
                break;
                
            default:
                $conn->callError($id, $topic, 'Unknown method: ' . $method);
        }
    }

    public function onSubscribe(ConnectionInterface $conn, Topic $topic): void
    {
        $this->logger->debug('Collaboration subscription created', [
            'connection_id' => $conn->resourceId,
            'topic' => $topic->getId(),
        ]);
    }

    public function onUnubscribe(ConnectionInterface $conn, Topic $topic): void
    {
        $this->logger->debug('Collaboration subscription removed', [
            'connection_id' => $conn->resourceId,
            'topic' => $topic->getId(),
        ]);
    }

    public function onPublish(ConnectionInterface $conn, Topic $topic, $message, array $exclude, array $eligible): void
    {
        $topicName = $topic->getId();
        
        $payload = json_decode($message, true) ?? $message;
        
        $this->logger->debug('Collaboration event published', [
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
        
        $this->logger->info('Collaboration user authenticated', [
            'connection_id' => $conn->resourceId,
            'user_id' => $conn->userId,
        ]);
        
        $conn->send(json_encode([
            'type' => 'authenticated',
            'user_id' => $conn->userId,
        ]));
    }

    private function handleJoinDocument(ConnectionInterface $conn, $id, array $params): void
    {
        if (!$conn->isAuthenticated) {
            $conn->callError($id, new Topic('wamp'), 'Not authenticated');
            return;
        }
        
        $documentId = $params['document_id'] ?? null;
        $revision = $params['revision'] ?? null;
        
        if (empty($documentId)) {
            $conn->callError($id, new Topic('wamp'), 'Document ID required');
            return;
        }
        
        $conn->documentId = $documentId;
        
        if (!isset($this->documentSessions[$documentId])) {
            $this->documentSessions[$documentId] = [
                'connections' => new SplObjectStorage(),
                'operations' => [],
                'cursors' => [],
            ];
        }
        
        $this->documentSessions[$documentId]['connections']->attach($conn);
        
        $this->logger->info('User joined document', [
            'user_id' => $conn->userId,
            'document_id' => $documentId,
        ]);
        
        $documentState = $this->getDocumentState($documentId, $revision);
        
        $collaborators = $this->getCollaboratorsInfo($documentId);
        
        $conn->send(json_encode([
            'type' => 'document_state',
            'document_id' => $documentId,
            'state' => $documentState,
            'revision' => $documentState['revision'],
            'collaborators' => $collaborators,
        ]));
        
        $this->broadcastToDocument($documentId, [
            'type' => 'user_joined',
            'user_id' => $conn->userId,
            'document_id' => $documentId,
        ], [$conn]);
    }

    private function handleLeaveDocumentRequest(ConnectionInterface $conn, $id, array $params): void
    {
        if (!$conn->isAuthenticated) {
            $conn->callError($id, new Topic('wamp'), 'Not authenticated');
            return;
        }
        
        if ($conn->documentId) {
            $this->handleLeaveDocument($conn);
        }
        
        $conn->send(json_encode([
            'type' => 'document_left',
        ]));
    }

    private function handleOperation(ConnectionInterface $conn, $id, array $params): void
    {
        if (!$conn->isAuthenticated || !$conn->documentId) {
            $conn->callError($id, new Topic('wamp'), 'Not in a document');
            return;
        }
        
        $operation = $params['operation'] ?? null;
        $revision = $params['revision'] ?? null;
        
        if ($operation === null || $revision === null) {
            $conn->callError($id, new Topic('wamp'), 'Operation and revision required');
            return;
        }
        
        $documentId = $conn->documentId;
        
        $appliedRevision = $this->applyOperation($documentId, $operation, $revision);
        
        $this->broadcastToDocument($documentId, [
            'type' => 'operation',
            'operation' => $operation,
            'revision' => $appliedRevision,
            'user_id' => $conn->userId,
        ], [], [$conn]);
    }

    private function handleCursorUpdate(ConnectionInterface $conn, $id, array $params): void
    {
        if (!$conn->isAuthenticated || !$conn->documentId) {
            return;
        }
        
        $position = $params['position'] ?? [];
        $selection = $params['selection'] ?? null;
        
        $cursorData = [
            'user_id' => $conn->userId,
            'position' => $position,
            'selection' => $selection,
            'timestamp' => time(),
        ];
        
        $this->cursorPositions[$conn->documentId][$conn->userId] = $cursorData;
        
        $this->broadcastToDocument($conn->documentId, [
            'type' => 'cursor_update',
            'cursor' => $cursorData,
        ], [], [$conn]);
    }

    private function handleAwarenessUpdate(ConnectionInterface $conn, $id, array $params): void
    {
        if (!$conn->isAuthenticated || !$conn->documentId) {
            return;
        }
        
        $this->broadcastToDocument($conn->documentId, [
            'type' => 'awareness_update',
            'user_id' => $conn->userId,
            'data' => $params['data'] ?? [],
        ], [], [$conn]);
    }

    private function handleGetCollaborators(ConnectionInterface $conn, $id, array $params): void
    {
        if (!$conn->isAuthenticated || !$conn->documentId) {
            $conn->callError($id, new Topic('wamp'), 'Not in a document');
            return;
        }
        
        $collaborators = $this->getCollaboratorsInfo($conn->documentId);
        
        $conn->send(json_encode([
            'type' => 'collaborators_list',
            'collaborators' => $collaborators,
        ]));
    }

    private function handleLeaveDocument(ConnectionInterface $conn): void
    {
        $documentId = $conn->documentId;
        
        if (!isset($this->documentSessions[$documentId])) {
            return;
        }
        
        $this->documentSessions[$documentId]['connections']->detach($conn);
        
        if (isset($this->cursorPositions[$documentId][$conn->userId])) {
            unset($this->cursorPositions[$documentId][$conn->userId]);
        }
        
        $this->broadcastToDocument($documentId, [
            'type' => 'user_left',
            'user_id' => $conn->userId,
            'document_id' => $documentId,
        ], []);
        
        if ($this->documentSessions[$documentId]['connections']->count() === 0) {
            unset($this->documentSessions[$documentId]);
        }
        
        $conn->documentId = null;
        
        $this->logger->info('User left document', [
            'user_id' => $conn->userId,
            'document_id' => $documentId,
        ]);
    }

    private function handleUserDisconnect(ConnectionConnection $conn): void
    {
        $this->logger->info('User disconnected from collaboration', [
            'user_id' => $conn->userId,
        ]);
    }

    private function validateAndDecodeToken(string $token): ?array
    {
        return ['user_id' => 123, 'exp' => time() + 3600];
    }

    private function generateConnectionId(): string
    {
        return 'collab_conn_' . bin2hex(random_bytes(8));
    }

    private function getDocumentState(string $documentId, ?int $revision): array
    {
        return [
            'id' => $documentId,
            'content' => 'Document content here',
            'revision' => 1,
        ];
    }

    private function getCollaboratorsInfo(string $documentId): array
    {
        if (!isset($this->documentSessions[$documentId])) {
            return [];
        }
        
        $collaborators = [];
        
        foreach ($this->documentSessions[$documentId]['connections'] as $conn) {
            $collaborators[] = [
                'user_id' => $conn->userId,
                'cursor' => $this->cursorPositions[$documentId][$conn->userId] ?? null,
            ];
        }
        
        return $collaborators;
    }

    private function applyOperation(string $documentId, array $operation, int $revision): int
    {
        return $revision + 1;
    }

    private function broadcastToDocument(string $documentId, array $message, array $exclude = [], array $eligible = []): void
    {
        if (!isset($this->documentSessions[$documentId])) {
            return;
        }
        
        $topic = new Topic('collab.document.' . $documentId);
        
        foreach ($this->documentSessions[$documentId]['connections'] as $conn) {
            if (in_array($conn, $exclude, true)) {
                continue;
            }
            $topic->add($conn);
        }
        
        $topic->broadcast(json_encode($message), $exclude, $eligible);
    }
}
