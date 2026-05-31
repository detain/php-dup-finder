<?php
declare(strict_types=1);

namespace Acme\Payments\Braintree;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Psr\Log\LoggerInterface;

final class BraintreeChargeClient
{
    public function __construct(
        private readonly Client $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $merchantId
    ) {
    }

    public function charge(string $orderId, int $amountMinor, string $currency, string $nonce): array
    {
        $idempotencyKey = hash('sha256', 'braintree|' . $orderId . '|' . $amountMinor . '|' . $currency);
        $body = json_encode([
            'merchantId' => $this->merchantId,
            'transaction' => [
                'orderId' => $orderId,
                'amount' => $amountMinor / 100,
                'currencyIsoCode' => $currency,
                'paymentMethodNonce' => $nonce,
            ],
        ], JSON_THROW_ON_ERROR);

        $attempt = 0;
        while ($attempt < 5) {
            $attempt++;
            try {
                $resp = $this->http->request('POST', 'https://api.sandbox.braintreegateway.com/transactions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
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
                    $this->logger->info('Braintree idempotency conflict', ['key' => $idempotencyKey]);
                    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                }
                if ($status >= 500 && $attempt < 5) {
                    usleep((int) (300000 * (2 ** ($attempt - 1))));
                    continue;
                }
                throw new \RuntimeException('Braintree HTTP ' . $status . ': ' . $raw);
            } catch (ConnectException $e) {
                $this->logger->warning('Braintree network blip', ['attempt' => $attempt, 'err' => $e->getMessage()]);
                if ($attempt >= 5) {
                    throw new \RuntimeException('Braintree unreachable', 0, $e);
                }
                usleep((int) (300000 * (2 ** ($attempt - 1))));
            }
        }
        throw new \RuntimeException('Braintree retry exhausted');
    }
}
