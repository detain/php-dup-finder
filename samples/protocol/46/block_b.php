<?php

declare(strict_types=1);

namespace App\WebSocket;

use App\Services\AuthenticationService;
use App\Services\RateLimiter;
use App\Contracts\WebSocketClientInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\LoopInterface;
use React\Socket\Server as ReactServer;

class WebSocketClient implements WebSocketClientInterface
{
    private AuthenticationService $authService;
    private RateLimiter $rateLimiter;
    private $server;
    private array $connectionHandlers = [];

    public function __construct(
        AuthenticationService $authService,
        RateLimiter $rateLimiter
    ) {
        $this->authService = $authService;
        $this->rateLimiter = $rateLimiter;
    }

    public function connect(string $uri, array $options = []): void
    {
        $loop = $this->createEventLoop();
        $connector = new \Ratchet\Client\Connector($loop);

        $headers = [];
        if (isset($options['token'])) {
            $headers['token'] = $options['token'];
        }

        $connector($uri, 'v2', $headers)
            ->then(function ($conn) use ($options) {
                $this->handleConnection($conn, $options);
            }, function ($e) {
                error_log("WebSocket connection failed: " . $e->getMessage());
            });

        $this->server = IoServer::create(new HttpServer(new WsServer($this)), $loop);
    }

    private function handleConnection($conn, array $options): void
    {
        $connectionId = uniqid('conn_');

        $this->connectionHandlers[$connectionId] = [
            'connection' => $conn,
            'options' => $options,
            'connected_at' => time(),
            'last_pong' => time(),
        ];

        $conn->on('message', function ($msg) use ($conn, $connectionId) {
            $this->handleMessage($conn, $connectionId, $msg);
        });

        $conn->on('close', function () use ($connectionId) {
            $this->handleClose($connectionId);
        });

        $conn->on('pong', function () use ($connectionId) {
            $this->connectionHandlers[$connectionId]['last_pong'] = time();
        });

        // Start ping interval
        $this->startPingInterval($connectionId);

        if (isset($options['onConnect'])) {
            $options['onConnect']($connectionId);
        }
    }

    private function handleMessage($conn, string $connectionId, $msg): void
    {
        $data = json_decode($msg, true);

        if (!$data || !isset($data['type'])) {
            return;
        }

        $handler = $this->connectionHandlers[$connectionId] ?? null;
        if (!$handler) {
            return;
        }

        switch ($data['type']) {
            case 'connected':
                if (isset($handler['options']['onConnected'])) {
                    $handler['options']['onConnected']($data);
                }
                break;

            case 'pong':
                // Already handled by pong callback
                break;

            case 'message':
                if (isset($handler['options']['onMessage'])) {
                    $handler['options']['onMessage']($data);
                }
                break;

            case 'error':
                error_log("Server error: " . ($data['message'] ?? 'Unknown'));
                if (isset($handler['options']['onError'])) {
                    $handler['options']['onError']($data);
                }
                break;
        }
    }

    private function handleClose(string $connectionId): void
    {
        $handler = $this->connectionHandlers[$connectionId] ?? null;

        if ($handler && isset($handler['options']['onClose'])) {
            $handler['options']['onClose']();
        }

        unset($this->connectionHandlers[$connectionId]);
    }

    private function startPingInterval(string $connectionId): void
    {
        // Send ping every 30 seconds
        $loop = $this->createEventLoop();
        $loop->addPeriodicTimer(30, function () use ($connectionId) {
            $handler = $this->connectionHandlers[$connectionId] ?? null;

            if ($handler) {
                $handler['connection']->send(json_encode(['type' => 'ping']));
            }
        });

        $loop->run();
    }

    public function send(string $connectionId, array $data): void
    {
        $handler = $this->connectionHandlers[$connectionId] ?? null;

        if ($handler) {
            $handler['connection']->send(json_encode($data));
        }
    }

    public function broadcast(array $data): void
    {
        foreach ($this->connectionHandlers as $handler) {
            $handler['connection']->send(json_encode($data));
        }
    }

    public function disconnect(string $connectionId, int $code = 1000): void
    {
        $handler = $this->connectionHandlers[$connectionId] ?? null;

        if ($handler) {
            $handler['connection']->close($code);
            unset($this->connectionHandlers[$connectionId]);
        }
    }

    private function createEventLoop(): LoopInterface
    {
        return \React\EventLoop\Factory::create();
    }
}
