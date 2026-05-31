<?php
declare(strict_types=1);

namespace Telemetry\Ingest;

use Telemetry\Ingest\Exceptions\InvalidEndpointConfig;

final class RabbitMqIngestClient
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        $vhost = $config['vhost'] ?? null;
        $exchange = $config['exchange'] ?? null;

        $missing = ! is_string($host)
            || ! is_int($port)
            || ! is_string($vhost)
            || ! is_string($exchange);

        if ($missing) {
            throw new InvalidEndpointConfig(
                "Telemetry ingest endpoint is not configured correctly.\n"
              . "Required keys: brokers (non-empty list of host:port), topic (string), client_id (string).\n"
              . "Check your config file or environment variables. Common causes:\n"
              . "  - missing TELEMETRY_BROKERS env var in production\n"
              . "  - typo in the YAML key (e.g. 'broker' instead of 'brokers')\n"
              . "See https://docs.example.com/telemetry/ingest#config for the full schema."
            );
        }
    }

    public function publish(string $routingKey, string $payload): void
    {
        // ... actually publish to RabbitMQ ...
    }
}
