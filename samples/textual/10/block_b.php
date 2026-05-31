<?php
declare(strict_types=1);

namespace Telemetry\Ingest;

use Telemetry\Ingest\Exceptions\InvalidEndpointConfig;

final class KinesisIngestClient
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
        $streamName = $config['stream_name'] ?? null;
        $region = $config['region'] ?? null;
        $partitionKey = $config['partition_key'] ?? null;

        if (! is_string($streamName) || ! is_string($region) || ! is_string($partitionKey)) {
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

    public function put(string $payload): void
    {
        // ... actually send to Kinesis ...
    }
}
