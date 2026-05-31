<?php

declare(strict_types=1);

namespace App\Http\Client;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

abstract class AbstractHttpClient
{
    protected const REQUEST_TIMEOUT = 30;
    protected const CONNECT_TIMEOUT = 10;
    protected const MAX_RETRIES = 3;
    protected const RETRY_DELAY = 500;
    protected const RETRY_STATUS_CODES = [408, 429, 500, 502, 503, 504];
    protected const POOL_SIZE = 20;
    protected const KEEP_ALIVE = 60;

    protected Client $client;

    protected abstract function getBaseUri(): string;
    protected abstract function getAuthHeaders(): array;

    protected function createClient(): Client
    {
        $stack = HandlerStack::create();

        $stack->push(Middleware::retry(
            fn($retries, $_, $response) => $this->shouldRetry($retries, $response),
            fn($retries) => $retries * self::RETRY_DELAY * 1000
        ));

        $stack->push(Middleware::mapRequest(function ($request) {
            foreach ($this->getAuthHeaders() as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            return $request->withHeader('User-Agent', 'AbstractHttpClient/1.0')
                          ->withHeader('Accept', 'application/json');
        }));

        return new Client([
            'base_uri' => $this->getBaseUri(),
            'timeout' => self::REQUEST_TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'handler' => $stack,
            'pool_size' => self::POOL_SIZE,
            'keep_alive' => self::KEEP_ALIVE,
        ]);
    }

    protected function shouldRetry(int $retries, $response): bool
    {
        if ($retries >= self::MAX_RETRIES) {
            return false;
        }

        return $response && in_array($response->getStatusCode(), self::RETRY_STATUS_CODES);
    }

    protected function request(string $method, string $uri, array $options = []): array
    {
        $response = $this->client->request($method, $uri, $options);
        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }
}
