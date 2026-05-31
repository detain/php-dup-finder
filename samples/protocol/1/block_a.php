<?php
declare(strict_types=1);

namespace Acme\Billing\Stripe;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

final class StripeChargeClient
{
    public function __construct(
        private readonly Client $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey
    ) {
    }

    public function createCharge(int $amountCents, string $currency, string $source): array
    {
        $attempt = 0;
        $maxAttempts = 4;
        $body = json_encode([
            'amount' => $amountCents,
            'currency' => $currency,
            'source' => $source,
        ], JSON_THROW_ON_ERROR);

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $response = $this->http->request('POST', 'https://api.stripe.com/v1/charges', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'User-Agent' => 'acme-stripe/1.0',
                    ],
                    'body' => $body,
                    'timeout' => 15.0,
                    'connect_timeout' => 5.0,
                ]);
                $status = $response->getStatusCode();
                $payload = (string) $response->getBody();
                if ($status >= 200 && $status < 300) {
                    return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                }
                if ($status >= 500 && $attempt < $maxAttempts) {
                    usleep((int) (250000 * (2 ** ($attempt - 1))));
                    continue;
                }
                $this->logger->error('Stripe non-2xx', ['status' => $status, 'body' => $payload]);
                throw new \RuntimeException('Stripe error: HTTP ' . $status);
            } catch (RequestException $e) {
                $this->logger->warning('Stripe transport failure', ['attempt' => $attempt, 'err' => $e->getMessage()]);
                if ($attempt >= $maxAttempts) {
                    throw new \RuntimeException('Stripe unreachable', 0, $e);
                }
                usleep((int) (250000 * (2 ** ($attempt - 1))));
            }
        }
        throw new \RuntimeException('Stripe retry exhausted');
    }
}
