<?php

declare(strict_types=1);

namespace App\Infrastructure\ExternalIntegrations;

use App\Infrastructure\Http\HttpClientInterface;

/**
 * Email service provider integration.
 * The HttpClientInterface is manually injected here, duplicated from
 * PaymentGatewayIntegration, ShippingCarrierIntegration, and other services.
 */
class EmailProviderIntegration
{
    private const BASE_URL = 'https://api.email-provider.example.com/v1';
    private const TIMEOUT_SECONDS = 30;

    private HttpClientInterface $httpClient;
    private string $apiKey;

    public function __construct(HttpClientInterface $httpClient, string $apiKey)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
    }

    public function sendEmail(array $emailData): array
    {
        $response = $this->httpClient->post(self::BASE_URL . '/send', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $emailData,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        if (!$response->isSuccessful()) {
            throw new EmailProviderException(
                "Failed to send email: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function sendBatch(array $emails): array
    {
        $response = $this->httpClient->post(self::BASE_URL . '/batch', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => ['emails' => $emails],
            'timeout' => 120,
        ]);

        if (!$response->isSuccessful()) {
            throw new EmailProviderException(
                "Failed to send batch email: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function getEmailStatus(string $messageId): array
    {
        $response = $this->httpClient->get(
            self::BASE_URL . "/messages/{$messageId}",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new EmailProviderException(
                "Failed to get email status: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function addContactToList(string $listId, array $contactData): bool
    {
        $response = $this->httpClient->post(
            self::BASE_URL . "/lists/{$listId}/contacts",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $contactData,
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new EmailProviderException(
                "Failed to add contact: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return true;
    }

    public function createTemplate(string $templateName, string $templateContent): array
    {
        $response = $this->httpClient->post(
            self::BASE_URL . '/templates',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'name' => $templateName,
                    'content' => $templateContent,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new EmailProviderException(
                "Failed to create template: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function getAnalytics(string $campaignId): array
    {
        $response = $this->httpClient->get(
            self::BASE_URL . "/campaigns/{$campaignId}/analytics",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new EmailProviderException(
                "Failed to get analytics: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }
}
