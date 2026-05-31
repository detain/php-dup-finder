<?php

declare(strict_types=1);

namespace App\Infrastructure\ExternalIntegrations;

use App\Infrastructure\Http\HttpClientInterface;

/**
 * Payment gateway integration service.
 * The HttpClientInterface is manually injected here, duplicated across
 * all external API integration services.
 */
class PaymentGatewayIntegration
{
    private const BASE_URL = 'https://api.payment-gateway.example.com/v1';
    private const TIMEOUT_SECONDS = 30;

    private HttpClientInterface $httpClient;
    private string $apiKey;

    public function __construct(HttpClientInterface $httpClient, string $apiKey)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
    }

    public function createPaymentIntent(array $paymentData): array
    {
        $response = $this->httpClient->post(self::BASE_URL . '/payment-intents', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $paymentData,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        if (!$response->isSuccessful()) {
            throw new PaymentGatewayException(
                "Failed to create payment intent: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function confirmPaymentIntent(string $intentId, array $confirmationData): array
    {
        $response = $this->httpClient->post(
            self::BASE_URL . "/payment-intents/{$intentId}/confirm",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $confirmationData,
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new PaymentGatewayException(
                "Failed to confirm payment intent: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function capturePaymentIntent(string $intentId, array $captureData = []): array
    {
        $response = $this->httpClient->post(
            self::BASE_URL . "/payment-intents/{$intentId}/capture",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $captureData,
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new PaymentGatewayException(
                "Failed to capture payment intent: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function refundPayment(string $transactionId, array $refundData): array
    {
        $response = $this->httpClient->post(
            self::BASE_URL . '/refunds',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => array_merge(
                    ['transaction_id' => $transactionId],
                    $refundData
                ),
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new PaymentGatewayException(
                "Failed to process refund: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function getTransaction(string $transactionId): array
    {
        $response = $this->httpClient->get(
            self::BASE_URL . "/transactions/{$transactionId}",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new PaymentGatewayException(
                "Failed to get transaction: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function listPaymentMethods(string $customerId): array
    {
        $response = $this->httpClient->get(
            self::BASE_URL . "/customers/{$customerId}/payment-methods",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new PaymentGatewayException(
                "Failed to list payment methods: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }
}
