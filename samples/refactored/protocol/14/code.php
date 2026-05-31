<?php
declare(strict_types=1);

namespace App\Integration\Clients;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

abstract class AbstractApiClient
{
    protected Client $httpClient;
    protected LoggerInterface $logger;
    protected int $connectTimeout = 30;
    protected int $timeout = 60;
    protected int $maxRetries = 3;

    abstract protected function getServiceName(): string;
    abstract protected function getBaseUri(): string;
    abstract protected function getAuthHeaders(): array;

    protected function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->configureTimeouts($config);
        
        $this->httpClient = new Client([
            'base_uri' => $this->getBaseUri(),
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => $this->getServiceName() . '/PHP/' . PHP_VERSION,
            ], $this->getAuthHeaders()),
            'handler' => $this->createHandlerStack(),
        ]);
    }

    protected function configureTimeouts(ConfigManager $config): void
    {
        $prefix = $this->getServiceName();
        $this->connectTimeout = (int)$config->get($prefix . '.connect_timeout', 30);
        $this->timeout = (int)$config->get($prefix . '.timeout', 60);
        $this->maxRetries = (int)$config->get($prefix . '.max_retries', 3);
    }

    protected function createHandlerStack(): HandlerStack
    {
        $stack = HandlerStack::create();
        
        $stack->push(Middleware::retry(
            function ($retries, $request, ?Response $response, ?\Exception $e) {
                return $this->shouldRetry($retries, $response, $e);
            },
            function ($retries) {
                return (int)pow(2, $retries) * 100;
            }
        ));
        
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            $this->logger->debug($this->getServiceName() . ' API request', [
                'method' => $request->getMethod(),
                'uri' => $request->getUri()->getPath(),
            ]);
            return $request;
        }));
        
        $stack->push(Middleware::mapResponse(function (Response $response) {
            $this->logger->debug($this->getServiceName() . ' API response', [
                'status' => $response->getStatusCode(),
            ]);
            return $response;
        }));
        
        return $stack;
    }

    protected function shouldRetry(int $retries, ?Response $response, ?\Exception $e): bool
    {
        if ($retries >= $this->maxRetries) {
            return false;
        }
        
        if ($response && $response->getStatusCode() >= 500) {
            $this->logger->warning($this->getServiceName() . ' API server error, retrying', [
                'retry' => $retries + 1,
                'status' => $response->getStatusCode(),
            ]);
            return true;
        }
        
        if ($response && $response->getStatusCode() === 429) {
            $retryAfter = $response->getHeaderLine('Retry-After');
            if ($retryAfter) {
                sleep((int)$retryAfter);
            }
            return true;
        }
        
        if ($e instanceof GuzzleException && 
            (str_contains($e->getMessage(), 'ECONNRESET') || 
             str_contains($e->getMessage(), 'timeout'))) {
            return true;
        }
        
        return false;
    }

    protected function executeRequest(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->httpClient->{$method}($uri, $options);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error($this->getServiceName() . ' API request failed', [
                'method' => $method,
                'uri' => $uri,
                'error' => $e->getMessage(),
            ]);
            throw new ApiException(
                $this->getServiceName() . ' API request failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
