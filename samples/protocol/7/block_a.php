<?php
declare(strict_types=1);

namespace Acme\Payments\Adyen;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Psr\Log\LoggerInterface;

final class AdyenChargeClient
{
    public function __construct(
        private readonly Client $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $merchantAccount
    ) {
    }

    public function charge(string $orderId, int $amountMinor, string $currency, string $paymentMethod): array
    {
        $idempotencyKey = hash('sha256', 'adyen|' . $orderId . '|' . $amountMinor . '|' . $currency);
        $body = json_encode([
            'merchantAccount' => $this->merchantAccount,
            'reference' => $orderId,
            'amount' => ['value' => $amountMinor, 'currency' => $currency],
            'paymentMethod' => ['encryptedCardNumber' => $paymentMethod],
        ], JSON_THROW_ON_ERROR);

        $attempt = 0;
        while ($attempt < 5) {
            $attempt++;
            try {
                $resp = $this->http->request('POST', 'https://checkout-test.adyen.com/v71/payments', [
                    'headers' => [
                        'x-API-key' => $this->apiKey,
                        'Idempotency-Key' => $idempotencyKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $body,
                    'timeout' => 20.0,
                ]);
                $status = $resp->getStatusCode();
                $raw = (string) $resp->getBody();
                if ($status >= 200 && $status < 300) {
                    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                }
                if ($status === 409) {
                    $this->logger->info('Adyen idempotency conflict', ['key' => $idempotencyKey]);
                    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                }
                if ($status >= 500 && $attempt < 5) {
                    usleep((int) (300000 * (2 ** ($attempt - 1))));
                    continue;
                }
                throw new \RuntimeException('Adyen HTTP ' . $status . ': ' . $raw);
            } catch (ConnectException $e) {
                $this->logger->warning('Adyen network blip', ['attempt' => $attempt, 'err' => $e->getMessage()]);
                if ($attempt >= 5) {
                    throw new \RuntimeException('Adyen unreachable', 0, $e);
                }
                usleep((int) (300000 * (2 ** ($attempt - 1))));
            }
        }
        throw new \RuntimeException('Adyen retry exhausted');
    }
}
