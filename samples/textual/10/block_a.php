<?php
declare(strict_types=1);

namespace Telemetry\Ingest;

use Telemetry\Ingest\Exceptions\InvalidEndpointConfig;

final class KafkaIngestClient
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
        $brokers = $config['brokers'] ?? null;
        $topic = $config['topic'] ?? null;
        $clientId = $config['client_id'] ?? null;

        if (! is_array($brokers) || $brokers === [] || ! is_string($topic) || ! is_string($clientId)) {
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

    public function send(string $payload): void
    {
        // ... actually send to Kafka ...
    }
}
