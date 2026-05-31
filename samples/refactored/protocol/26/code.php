<?php
declare(strict_types=1);

namespace App\Services\Streaming;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

trait KeepaliveStreamingTrait
{
    private bool $isConnected = false;
    private int $keepaliveInterval = 30;
    private int $lastPingTime;
    private int $maxMissedPings = 3;
    protected LoggerInterface $logger;
    protected ConfigManager $config;
    protected $stream;

    abstract protected function createChannel(string $endpoint);
    abstract protected function readFromChannel(): ?array;
    abstract protected function writeToChannel(array $data): void;
    abstract protected function getDefaultEndpoint(): string;

    public function connect(string $endpoint, array $metadata = []): void
    {
        try {
            $this->stream = $this->createChannel($endpoint);
            $this->isConnected = true;
            $this->lastPingTime = time();
            
            $this->logger->info(static::class . ' connected', [
                'endpoint' => $endpoint,
            ]);
            
            $this->startKeepaliveLoop();
            
        } catch (\Exception $e) {
            $this->logger->error(static::class . ' connection failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function receive(): ?array
    {
        if (!$this->isConnected) {
            return null;
        }
        
        try {
            $data = $this->readFromChannel();
            
            if ($data === null) {
                $this->handleDisconnect();
                return null;
            }
            
            $this->lastPingTime = time();
            return $data;
            
        } catch (\Exception $e) {
            $this->logger->error(static::class . ' read error', [
                'error' => $e->getMessage(),
            ]);
            $this->handleDisconnect();
            return null;
        }
    }

    private function startKeepaliveLoop(): void
    {
        $callback = function () {
            while ($this->isConnected) {
                sleep($this->keepaliveInterval);
                
                if (!$this->isConnected) {
                    break;
                }
                
                $timeSinceLastPing = time() - $this->lastPingTime;
                
                if ($timeSinceLastPing > ($this->keepaliveInterval * $this->maxMissedPings)) {
                    $this->logger->warning(static::class . ' missed keepalive pings', [
                        'missed' => $timeSinceLastPing / $this->keepaliveInterval,
                    ]);
                    $this->handleDisconnect();
                    break;
                }
                
                try {
                    $this->sendPing();
                    $this->logger->debug(static::class . ' ping sent');
                } catch (\Exception $e) {
                    $this->logger->warning(static::class . ' ping failed', [
                        'error' => $e->getMessage(),
                    ]);
                    $this->handleDisconnect();
                    break;
                }
            }
        };
        
        $callback();
    }

    private function sendPing(): void
    {
        $pingMessage = [
            'type' => 'ping',
            'timestamp' => time(),
            'stream_type' => static::class,
        ];
        
        $this->writeToChannel($pingMessage);
    }

    private function handleDisconnect(): void
    {
        if (!$this->isConnected) {
            return;
        }
        
        $this->isConnected = false;
        
        $this->logger->info(static::class . ' disconnected, attempting reconnect');
        
        sleep(5);
        
        try {
            $this->connect($this->getDefaultEndpoint());
        } catch (\Exception $e) {
            $this->logger->error(static::class . ' reconnect failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
