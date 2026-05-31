<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use App\Attributes\Configuration;

#[Configuration('messaging')]
final class MessagingConfig
{
    public function __construct(
        public readonly int $timeout = 15,
        public readonly int $connectTimeout = 5,
        public readonly int $maxRetries = 3,
        public readonly int $retryDelay = 500,
        public readonly int $poolSize = 10,
        public readonly int $keepAlive = 30,
        public readonly int $batchSize = 100,
        public readonly int $chunkSize = 20,
    ) {}
}

abstract class AbstractMessagingService
{
    protected abstract function getMessagingConfig(): MessagingConfig;

    protected function executeWithRetry(callable $operation): mixed
    {
        $config = $this->getMessagingConfig();
        $attempts = 0;

        while ($attempts < $config->maxRetries) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                $attempts++;
                $this->logger->warning('Messaging operation failed', [
                    'attempt' => $attempts,
                    'max_retries' => $config->maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts >= $config->maxRetries) {
                    throw $e;
                }

                usleep($config->retryDelay * 1000 * $attempts);
            }
        }
    }

    protected function sendInChunks(array $items, callable $sendSingle): array
    {
        $config = $this->getMessagingConfig();
        $chunks = array_chunk($items, $config->chunkSize);
        $results = ['sent' => 0, 'failed' => 0];

        foreach ($chunks as $chunk) {
            foreach ($chunk as $item) {
                if ($sendSingle($item)) {
                    $results['sent']++;
                } else {
                    $results['failed']++;
                }
            }

            if (count($chunks) > 1) {
                usleep($config->retryDelay * 1000);
            }
        }

        return $results;
    }
}
