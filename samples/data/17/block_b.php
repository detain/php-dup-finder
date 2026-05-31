<?php
declare(strict_types=1);

namespace GeoLocator\Clients\Geocoding;

use Psr\Log\LoggerInterface;
use GeoLocator\Geocoding\Entities\LocationQuery;
use GeoLocator\Geocoding\Exceptions\ApiException;

final class GeocodingApiClient
{
    private const BASE_URL = 'https://maps.googleapis.com/maps/api/geocode';
    private const GEOCODE_ENDPOINT = '/json';
    private const REVERSE_GEOCODE_ENDPOINT = '/json';
    private const PLACES_ENDPOINT = '/place/autocomplete';

    private const HTTP_OK = 200;
    private const HTTP_CREATED = 201;
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_FORBIDDEN = 403;
    private const HTTP_NOT_FOUND = 404;
    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_SERVER_ERROR = 500;
    private const HTTP_GATEWAY_TIMEOUT = 504;

    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const READ_TIMEOUT_SECONDS = 30;
    private const TOTAL_TIMEOUT_SECONDS = 45;

    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MILLISECONDS = 500;
    private const CIRCUIT_BREAKER_THRESHOLD = 5;
    private const CIRCUIT_BREAKER_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
    ) {}

    public function geocodeAddress(LocationQuery $query): GeocodingResult
    {
        $this->logger->info('Geocoding address', [
            'address' => $query->getAddress(),
            'region' => $query->getRegion(),
        ]);

        $url = $this->buildUrl(self::GEOCODE_ENDPOINT, [
            'address' => $query->getAddress(),
            'key' => $this->apiKey,
            'region' => $query->getRegion(),
        ]);

        $response = $this->makeRequest($url);

        if ($response->getStatusCode() === self::HTTP_NOT_FOUND) {
            throw new ApiException('Address not found: ' . $query->getAddress());
        }

        if ($response->getStatusCode() === self::HTTP_UNAUTHORIZED) {
            throw new ApiException('Invalid API key');
        }

        if ($response->getStatusCode() === self::HTTP_TOO_MANY_REQUESTS) {
            throw new ApiException('Geocoding rate limit exceeded');
        }

        if ($response->getStatusCode() !== self::HTTP_OK) {
            throw new ApiException('Geocoding API error: ' . $response->getStatusCode());
        }

        return GeocodingResult::fromApiResponse($response->getBody());
    }

    public function reverseGeocode(float $latitude, float $longitude): ReverseGeocodingResult
    {
        $this->logger->info('Reverse geocoding coordinates', [
            'lat' => $latitude,
            'lng' => $longitude,
        ]);

        $url = $this->buildUrl(self::REVERSE_GEOCODE_ENDPOINT, [
            'latlng' => $latitude . ',' . $longitude,
            'key' => $this->apiKey,
        ]);

        $response = $this->makeRequest($url);

        if ($response->getStatusCode() === self::HTTP_BAD_REQUEST) {
            throw new ApiException('Invalid coordinates');
        }

        if ($response->getStatusCode() === self::HTTP_UNAUTHORIZED) {
            throw new ApiException('Invalid API key');
        }

        if ($response->getStatusCode() !== self::HTTP_OK) {
            throw new ApiException('Reverse geocoding API error: ' . $response->getStatusCode());
        }

        return ReverseGeocodingResult::fromApiResponse($response->getBody());
    }

    private function makeRequest(string $url): ApiResponse
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::READ_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: GeoLocator-Client/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new ApiException('cURL error: ' . $error);
        }

        return new ApiResponse($statusCode, $body);
    }

    private function buildUrl(string $endpoint, array $queryParams): string
    {
        $queryString = http_build_query($queryParams);
        return self::BASE_URL . $endpoint . '?' . $queryString;
    }

    public function isRetryableError(int $statusCode): bool
    {
        return in_array($statusCode, [
            self::HTTP_SERVER_ERROR,
            self::HTTP_GATEWAY_TIMEOUT,
            self::HTTP_TOO_MANY_REQUESTS,
        ], true);
    }

    public function shouldOpenCircuit(int $failureCount): bool
    {
        return $failureCount >= self::CIRCUIT_BREAKER_THRESHOLD;
    }
}
