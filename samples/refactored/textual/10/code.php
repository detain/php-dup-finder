<?php
declare(strict_types=1);

namespace Telemetry\Ingest\Exceptions;

final class InvalidEndpointConfig extends \RuntimeException
{
    /** @param array<string,string> $requiredKeys key => human description */
    public static function forBackend(string $backendLabel, array $requiredKeys, string $envHint): self
    {
        $required = implode(', ', array_map(
            static fn (string $k, string $desc): string => "{$k} ({$desc})",
            array_keys($requiredKeys),
            array_values($requiredKeys),
        ));

        $msg = "Telemetry ingest endpoint ({$backendLabel}) is not configured correctly.\n"
             . "Required keys: {$required}.\n"
             . "Check your config file or environment variables. Common causes:\n"
             . "  - missing {$envHint} env var in production\n"
             . "  - typo in the YAML key (e.g. singular instead of plural)\n"
             . "See https://docs.example.com/telemetry/ingest#config for the full schema.";
        return new self($msg);
    }
}

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
            throw InvalidEndpointConfig::forBackend(
                backendLabel: 'kafka',
                requiredKeys: [
                    'brokers' => 'non-empty list of host:port',
                    'topic' => 'string',
                    'client_id' => 'string',
                ],
                envHint: 'TELEMETRY_BROKERS',
            );
        }
    }
}
