<?php
declare(strict_types=1);

namespace CurrencyAPI\Clients\Exchange;

use Psr\Log\LoggerInterface;
use CurrencyAPI\Exchange\Entities\ConversionRequest;
use CurrencyAPI\Exchange\Exceptions\ApiException;

final class ExchangeRateApiClient
{
    private const BASE_URL = 'https://api.exchangerate-api.com/v4';
    private const LATEST_ENDPOINT = '/latest';
    private const HISTORICAL_ENDPOINT = '/history';
    private const CONVERT_ENDPOINT = '/convert';

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

    public function getLatestRates(string $baseCurrency): ExchangeRates
    {
        $this->logger->info('Fetching latest exchange rates', [
            'base' => $baseCurrency,
        ]);

        $url = $this->buildUrl(self::LATEST_ENDPOINT . '/' . $baseCurrency, []);

        $response = $this->makeRequest($url);

        if ($response->getStatusCode() === self::HTTP_NOT_FOUND) {
            throw new ApiException('Currency not found: ' . $baseCurrency);
        }

        if ($response->getStatusCode() === self::HTTP_UNAUTHORIZED) {
            throw new ApiException('Invalid API key');
        }

        if ($response->getStatusCode() === self::HTTP_TOO_MANY_REQUESTS) {
            throw new ApiException('Exchange rate API rate limit exceeded');
        }

        if ($response->getStatusCode() !== self::HTTP_OK) {
            throw new ApiException('Exchange rate API error: ' . $response->getStatusCode());
        }

        return ExchangeRates::fromApiResponse($response->getBody());
    }

    public function convertCurrency(ConversionRequest $request): ConversionResult
    {
        $this->logger->info('Converting currency', [
            'from' => $request->getFromCurrency(),
            'to' => $request->getToCurrency(),
            'amount' => $request->getAmount(),
        ]);

        $url = $this->buildUrl(self::CONVERT_ENDPOINT, [
            'from' => $request->getFromCurrency(),
            'to' => $request->getToCurrency(),
            'amount' => $request->getAmount(),
        ]);

        $response = $this->makeRequest($url);

        if ($response->getStatusCode() === self::HTTP_BAD_REQUEST) {
            throw new ApiException('Invalid currency conversion request');
        }

        if ($response->getStatusCode() === self::HTTP_UNAUTHORIZED) {
            throw new ApiException('Invalid API key');
        }

        if ($response->getStatusCode() !== self::HTTP_OK) {
            throw new ApiException('Conversion API error: ' . $response->getStatusCode());
        }

        return ConversionResult::fromApiResponse($response->getBody());
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
                'User-Agent: CurrencyAPI-Client/1.0',
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
        if (empty($queryParams)) {
            return self::BASE_URL . $endpoint;
        }
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
