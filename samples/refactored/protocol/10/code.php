<?php
declare(strict_types=1);

namespace Acme\Events;

use Psr\Log\LoggerInterface;

final class StreamingConsumer
{
    /** @param array<string,string> $headers */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $headers,
        private readonly string $tag,
        private readonly int $heartbeatTimeout = 60
    ) {
    }

    public function consume(string $url, callable $handler): void
    {
        $headerLines = '';
        foreach ($this->headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }
        $stream = fopen($url, 'r', false, stream_context_create([
            'http' => [
                'header' => $headerLines . "Accept: text/event-stream\r\n",
                'timeout' => 0,
                'method' => 'GET',
            ],
        ]));
        if ($stream === false) {
            throw new \RuntimeException($this->tag . ' stream connect failed');
        }

        $heartbeat = time();
        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                if (time() - $heartbeat > $this->heartbeatTimeout) {
                    $this->logger->warning($this->tag . ' stream stalled, reconnecting');
                    break;
                }
                usleep(100000);
                continue;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '' || str_starts_with($line, ':')) {
                $heartbeat = time();
                continue;
            }
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $json = trim(substr($line, 5));
            try {
                $event = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->warning($this->tag . ' invalid frame', ['line' => $json]);
                continue;
            }
            $heartbeat = time();
            $handler($event);
        }
        fclose($stream);
    }
}
