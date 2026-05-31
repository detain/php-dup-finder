<?php
declare(strict_types=1);

namespace App\Payment\Gateways;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

abstract class AbstractPaymentGateway
{
    protected Client $httpClient;
    protected LoggerInterface $logger;
    protected int $connectTimeout = 30;
    protected int $timeout = 60;
    protected int $maxRetries = 3;

    abstract protected function getBaseUri(): string;
    abstract protected function getAuthHeaders(): array;
    abstract protected function getGatewayName(): string;

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
                'User-Agent' => $this->getGatewayName() . '/PHP/' . PHP_VERSION,
            ], $this->getAuthHeaders()),
            'handler' => $this->createHandlerStack(),
        ]);
    }

    protected function configureTimeouts(ConfigManager $config): void
    {
        $this->connectTimeout = (int)$config->get(
            $this->getGatewayName() . '.connect_timeout',
            30
        );
        $this->timeout = (int)$config->get(
            $this->getGatewayName() . '.timeout',
            60
        );
        $this->maxRetries = (int)$config->get(
            $this->getGatewayName() . '.max_retries',
            3
        );
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
            $this->logger->debug($this->getGatewayName() . ' API request', [
                'method' => $request->getMethod(),
                'uri' => $request->getUri()->getPath(),
            ]);
            return $this->augmentRequest($request);
        }));
        
        $stack->push(Middleware::mapResponse(function (Response $response) {
            $this->logger->debug($this->getGatewayName() . ' API response', [
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
            $this->logger->warning($this->getGatewayName() . ' API server error, retrying', [
                'retry' => $retries + 1,
                'max_retries' => $this->maxRetries,
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
        
        if ($e instanceof GuzzleException) {
            if (str_contains($e->getMessage(), 'ECONNRESET') ||
                str_contains($e->getMessage(), 'timeout')) {
                return true;
            }
        }
        
        return false;
    }

    protected function augmentRequest(RequestInterface $request): RequestInterface
    {
        return $request;
    }

    protected function executeRequest(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->httpClient->{$method}($uri, $options);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error($this->getGatewayName() . ' request failed', [
                'method' => $method,
                'uri' => $uri,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            throw new PaymentGatewayException(
                $this->getGatewayName() . ' request failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
