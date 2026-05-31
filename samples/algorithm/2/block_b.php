<?php

declare(strict_types=1);

namespace Acme\Integrations\Stripe;

use Acme\Integrations\Http\HttpClient;
use Acme\Integrations\Stripe\Exception\StripeServiceUnavailableException;
use Psr\Log\LoggerInterface;

final class ChargeCreator
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createCharge(array $payload, string $idempotencyKey): array
    {
        $attempt = 0;
        $maxAttempts = 5;
        $baseDelayMs = 500;
        $factor = 2.0;

        while (true) {
            try {
                return $this->http->post('/v1/charges', $payload, [
                    'Idempotency-Key' => $idempotencyKey,
                ]);
            } catch (StripeServiceUnavailableException $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    $this->logger->error('stripe.charge.exhausted', [
                        'idempotency_key' => $idempotencyKey,
                        'attempts' => $attempt,
                    ]);
                    throw $e;
                }

                $delay = (int) ($baseDelayMs * ($factor ** ($attempt - 1)));
                $jitter = random_int(0, (int) ($delay * 0.30));
                $sleepMs = $delay + $jitter;

                $this->logger->info('stripe.charge.retry', [
                    'attempt' => $attempt,
                    'sleep_ms' => $sleepMs,
                ]);

                usleep($sleepMs * 1000);
            }
        }
    }
}
