<?php

declare(strict_types=1);

namespace App\Infrastructure\ExternalIntegrations;

use App\Infrastructure\Http\HttpClientInterface;

/**
 * Shipping carrier integration service.
 * The HttpClientInterface is manually injected here, duplicated from
 * PaymentGatewayIntegration and other integration services.
 */
class ShippingCarrierIntegration
{
    private const BASE_URL = 'https://api.shipping-carrier.example.com/v2';
    private const TIMEOUT_SECONDS = 30;

    private HttpClientInterface $httpClient;
    private string $apiKey;

    public function __construct(HttpClientInterface $httpClient, string $apiKey)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
    }

    public function getShippingRates(
        array $packageDetails,
        string $originZipCode,
        string $destinationZipCode
    ): array {

        $response = $this->httpClient->post(self::BASE_URL . '/rates', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'package' => $packageDetails,
                'origin' => ['zip' => $originZipCode],
                'destination' => ['zip' => $destinationZipCode],
            ],
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        if (!$response->isSuccessful()) {
            throw new ShippingCarrierException(
                "Failed to get shipping rates: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function createShipment(
        string $rateId,
        array $shipperDetails,
        array $recipientDetails
    ): array {

        $response = $this->httpClient->post(self::BASE_URL . '/shipments', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'rate_id' => $rateId,
                'shipper' => $shipperDetails,
                'recipient' => $recipientDetails,
            ],
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        if (!$response->isSuccessful()) {
            throw new ShippingCarrierException(
                "Failed to create shipment: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function getShipmentLabel(string $shipmentId): string
    {
        $response = $this->httpClient->get(
            self::BASE_URL . "/shipments/{$shipmentId}/label",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/pdf',
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new ShippingCarrierException(
                "Failed to get shipment label: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getBody();
    }

    public function trackShipment(string $trackingNumber): array
    {
        $response = $this->httpClient->get(
            self::BASE_URL . "/track/{$trackingNumber}",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new ShippingCarrierException(
                "Failed to track shipment: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function schedulePickup(
        string $shipmentId,
        \DateTimeImmutable $pickupDate,
        array $locationDetails
    ): array {

        $response = $this->httpClient->post(
            self::BASE_URL . "/shipments/{$shipmentId}/pickup",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'pickup_date' => $pickupDate->format('Y-m-d'),
                    'location' => $locationDetails,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new ShippingCarrierException(
                "Failed to schedule pickup: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return $response->getJson();
    }

    public function cancelShipment(string $shipmentId): bool
    {
        $response = $this->httpClient->delete(
            self::BASE_URL . "/shipments/{$shipmentId}",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (!$response->isSuccessful()) {
            throw new ShippingCarrierException(
                "Failed to cancel shipment: {$response->getErrorMessage()}",
                $response->getStatusCode()
            );
        }

        return true;
    }
}
