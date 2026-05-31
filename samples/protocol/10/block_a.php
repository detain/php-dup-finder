<?php
declare(strict_types=1);

namespace Acme\Events\Slack;

use Psr\Log\LoggerInterface;

final class SlackRtmConsumer
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $token
    ) {
    }

    public function consume(string $channel, callable $handler): void
    {
        $url = 'https://slack.com/api/rtm.connect?channel=' . urlencode($channel);
        $stream = fopen($url, 'r', false, stream_context_create([
            'http' => [
                'header' => "Authorization: Bearer " . $this->token . "\r\nAccept: text/event-stream\r\n",
                'timeout' => 0,
                'method' => 'GET',
            ],
        ]));
        if ($stream === false) {
            throw new \RuntimeException('Slack stream connect failed');
        }

        $heartbeat = time();
        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                if (time() - $heartbeat > 60) {
                    $this->logger->warning('Slack stream stalled, reconnecting');
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
                $this->logger->warning('Slack invalid frame', ['line' => $json]);
                continue;
            }
            $heartbeat = time();
            $handler($event);
        }
        fclose($stream);
    }
}
