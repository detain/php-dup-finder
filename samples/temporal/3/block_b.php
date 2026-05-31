<?php
declare(strict_types=1);

namespace DataIngest\Imports\Ndjson;

use Psr\Log\LoggerInterface;

final class EventNdjsonImporter
{
    public function __construct(
        private EventStore $store,
        private LoggerInterface $log,
    ) {}

    /**
     * @return array{events:int,errors:int}
     */
    public function import(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("cannot open {$path}");
        }

        $events = 0;
        $errors = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    $decoded = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                    if (!isset($decoded['type'], $decoded['payload'])) {
                        $errors++;
                        continue;
                    }
                    $this->store->append((string) $decoded['type'], (array) $decoded['payload']);
                    $events++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->log->warning('ndjson.row_failed', ['err' => $e->getMessage()]);
                }
            }
            $this->log->info('ndjson.import.ok', ['events' => $events, 'errors' => $errors]);
            return ['events' => $events, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }
}
