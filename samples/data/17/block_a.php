<?php
declare(strict_types=1);

namespace WeatherAPI\Clients\Weather;

use Psr\Log\LoggerInterface;
use WeatherAPI\Weather\Entities\WeatherRequest;
use WeatherAPI\Weather\Exceptions\ApiException;

final class WeatherApiClient
{
    private const BASE_URL = 'https://api.openweathermap.org/data/2.5';
    private const WEATHER_ENDPOINT = '/weather';
    private const FORECAST_ENDPOINT = '/forecast';
    private const UV_INDEX_ENDPOINT = '/uvi';

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

    public function getCurrentWeather(WeatherRequest $request): WeatherData
    {
        $this->logger->info('Fetching current weather', [
            'city' => $request->getCity(),
            'country' => $request->getCountryCode(),
        ]);

        $url = $this->buildUrl(self::WEATHER_ENDPOINT, [
            'q' => $request->getCity() . ',' . $request->getCountryCode(),
            'appid' => $this->apiKey,
            'units' => $request->getUnits(),
        ]);

        $response = $this->makeRequest($url);

        if ($response->getStatusCode() === self::HTTP_NOT_FOUND) {
            throw new ApiException('City not found: ' . $request->getCity());
        }

        if ($response->getStatusCode() === self::HTTP_UNAUTHORIZED) {
            throw new ApiException('Invalid API key');
        }

        if ($response->getStatusCode() !== self::HTTP_OK) {
            throw new ApiException('Weather API error: ' . $response->getStatusCode());
        }

        return WeatherData::fromApiResponse($response->getBody());
    }

    public function getForecast(WeatherRequest $request, int $days = 5): ForecastData
    {
        $this->logger->info('Fetching weather forecast', [
            'city' => $request->getCity(),
            'days' => $days,
        ]);

        $url = $this->buildUrl(self::FORECAST_ENDPOINT, [
            'q' => $request->getCity() . ',' . $request->getCountryCode(),
            'appid' => $this->apiKey,
            'units' => $request->getUnits(),
            'cnt' => $days * 8,
        ]);

        $response = $this->makeRequest($url);

        if ($response->getStatusCode() === self::HTTP_NOT_FOUND) {
            throw new ApiException('City not found: ' . $request->getCity());
        }

        if ($response->getStatusCode() === self::HTTP_TOO_MANY_REQUESTS) {
            throw new ApiException('Rate limit exceeded. Please try again later.');
        }

        if ($response->getStatusCode() !== self::HTTP_OK) {
            throw new ApiException('Forecast API error: ' . $response->getStatusCode());
        }

        return ForecastData::fromApiResponse($response->getBody());
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
                'User-Agent: WeatherAPI-Client/1.0',
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
