<?php
declare(strict_types=1);

namespace Billing\Payment;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class PaymentGatewayClient
{
    private const MAX_RETRIES = 3;
    private const BASE_DELAY_MS = 100;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    public function charge(string $paymentMethodId, Money $amount): ChargeResult
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $response = $this->httpClient->request('POST', $this->getApiUrl() . '/charges', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->getApiKey(),
                        'Content-Type' => 'application/json'
                    ],
                    'json' => [
                        'amount' => $amount->getCents(),
                        'currency' => $amount->getCurrency(),
                        'payment_method' => $paymentMethodId
                    ],
                    'timeout' => 30
                ]);

                $data = $response->toArray();

                if ($response->getStatusCode() >= 400) {
                    throw new PaymentDeclinedException(
                        $data['error']['message'] ?? 'Payment declined',
                        $data['error']['code'] ?? 'unknown'
                    );
                }

                $this->logger->info('Charge successful', [
                    'charge_id' => $data['id'],
                    'amount' => $amount->getCents()
                ]);

                return ChargeResult::successful($data['id']);

            } catch (TransportExceptionInterface $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < self::MAX_RETRIES) {
                    $delay = self::BASE_DELAY_MS * (2 ** ($attempt - 1));
                    $jitter = random_int(0, (int)(self::BASE_DELAY_MS * 0.1 * $attempt));

                    $this->logger->warning('Charge attempt failed, retrying', [
                        'attempt' => $attempt,
                        'delay_ms' => $delay + $jitter,
                        'error' => $e->getMessage()
                    ]);

                    usleep(($delay + $jitter) * 1000);
                }
            } catch (PaymentDeclinedException $e) {
                // Don't retry declined payments
                $this->logger->info('Charge declined', [
                    'error_code' => $e->getErrorCode()
                ]);
                return ChargeResult::declined($e->getErrorCode(), $e->getMessage());
            }
        }

        $this->logger->error('Charge failed after max retries', [
            'payment_method' => $paymentMethodId,
            'attempts' => $attempt
        ]);

        return ChargeResult::failed($lastException);

    }

    private function getApiUrl(): string
    {
        return $_ENV['PAYMENT_GATEWAY_URL'] ?? 'https://api.stripe.com/v1';
    }

    private function getApiKey(): string
    {
        return $_ENV['STRIPE_SECRET_KEY'] ?? '';
    }
}
