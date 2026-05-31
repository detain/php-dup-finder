<?php
declare(strict_types=1);

namespace App\Services\Realtime;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

abstract class AbstractWebSocketService
{
    protected LoggerInterface $logger;
    protected ConfigManager $config;
    protected $socket;
    protected bool $isConnected = false;
    protected string $url;
    protected int $reconnectDelay = 1000;
    protected int $maxReconnectDelay = 30000;
    protected int $heartbeatInterval = 30;
    protected int $lastHeartbeat;

    abstract protected function getDefaultUrl(): string;
    abstract protected function handleMessageType(array $message): void;

    public function connect(): bool
    {
        try {
            $this->socket = $this->createSocketConnection($this->url);
            $this->isConnected = true;
            $this->lastHeartbeat = time();
            $this->reconnectDelay = 1000;
            
            $this->logger->info(static::class . ' WebSocket connected', ['url' => $this->url]);
            
            $this->startHeartbeat();
            $this->startMessageLoop();
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error(static::class . ' WebSocket connection failed', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
            $this->scheduleReconnect();
            return false;
        }
    }

    public function send(array $message): bool
    {
        if (!$this->isConnected) {
            $this->logger->warning('Cannot send message, not connected');
            return false;
        }
        
        try {
            $data = json_encode($message);
            $this->writeToSocket($this->socket, $data);
            
            $this->logger->debug(static::class . ' message sent', ['type' => $message['type'] ?? 'unknown']);
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to send message', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function startHeartbeat(): void
    {
        $callback = function () {
            while ($this->isConnected) {
                sleep($this->heartbeatInterval);
                
                if (!$this->isConnected) {
                    break;
                }
                
                try {
                    $this->sendHeartbeat();
                    $this->logger->debug(static::class . ' heartbeat sent');
                } catch (\Exception $e) {
                    $this->logger->warning(static::class . ' heartbeat failed', [
                        'error' => $e->getMessage(),
                    ]);
                    $this->handleDisconnect();
                    break;
                }
            }
        };
        
        $callback();
    }

    protected function sendHeartbeat(): void
    {
        $heartbeat = [
            'type' => 'ping',
            'timestamp' => time(),
            'service' => static::class,
        ];
        
        $this->writeToSocket($this->socket, json_encode($heartbeat));
    }

    protected function startMessageLoop(): void
    {
        $callback = function () {
            while ($this->isConnected) {
                try {
                    $data = $this->readFromSocket($this->socket);
                    
                    if ($data === null) {
                        $this->handleDisconnect();
                        break;
                    }
                    
                    $message = json_decode($data, true);
                    $this->handleMessageType($message);
                    
                } catch (\Exception $e) {
                    $this->logger->error(static::class . ' message loop error', [
                        'error' => $e->getMessage(),
                    ]);
                    $this->handleDisconnect();
                    break;
                }
            }
        };
        
        $callback();
    }

    protected function handleDisconnect(): void
    {
        if (!$this->isConnected) {
            return;
        }
        
        $this->isConnected = false;
        $this->logger->info(static::class . ' WebSocket disconnected');
        
        $this->scheduleReconnect();
    }

    protected function scheduleReconnect(): void
    {
        $this->logger->info('Scheduling ' . static::class . ' reconnect', [
            'delay_ms' => $this->reconnectDelay,
        ]);
        
        usleep($this->reconnectDelay * 1000);
        
        $this->reconnectDelay = min($this->reconnectDelay * 2, $this->maxReconnectDelay);
        
        $this->connect();
    }

    abstract protected function createSocketConnection(string $url);
    abstract protected function writeToSocket($socket, string $data): void;
    abstract protected function readFromSocket($socket): ?string;
}
