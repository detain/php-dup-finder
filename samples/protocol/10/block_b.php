<?php
declare(strict_types=1);

namespace Acme\Events\GitHub;

use Psr\Log\LoggerInterface;

final class GitHubEventsConsumer
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $token
    ) {
    }

    public function consume(string $org, callable $handler): void
    {
        $url = 'https://api.github.com/orgs/' . urlencode($org) . '/events?stream=true';
        $stream = fopen($url, 'r', false, stream_context_create([
            'http' => [
                'header' => "Authorization: Bearer " . $this->token . "\r\nAccept: text/event-stream\r\nUser-Agent: acme-events/1.0\r\n",
                'timeout' => 0,
                'method' => 'GET',
            ],
        ]));
        if ($stream === false) {
            throw new \RuntimeException('GitHub stream connect failed');
        }

        $heartbeat = time();
        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                if (time() - $heartbeat > 60) {
                    $this->logger->warning('GitHub stream stalled, reconnecting');
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
                $this->logger->warning('GitHub invalid frame', ['line' => $json]);
                continue;
            }
            $heartbeat = time();
            $handler($event);
        }
        fclose($stream);
    }
}
