<?php
declare(strict_types=1);

namespace App\Payment\Gateways;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

final class PayPalPaymentGateway
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private string $clientId;
    private string $clientSecret;
    private string $mode;
    private int $connectTimeout = 30;
    private int $timeout = 60;
    private int $maxRetries = 3;
    private ?string $accessToken = null;
    private ?int $tokenExpiry = null;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->clientId = $config->get('paypal.client_id');
        $this->clientSecret = $config->get('paypal.client_secret');
        $this->mode = $config->get('paypal.mode', 'sandbox');
        
        $baseUri = $this->mode === 'live' 
            ? 'https://api.paypal.com/v2/' 
            : 'https://api.sandbox.paypal.com/v2/';
        
        $this->httpClient = new Client([
            'base_uri' => $baseUri,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'PayPal-Request-Id' => $this->generateRequestId(),
            ],
            'handler' => $this->createHandlerStack(),
        ]);
    }

    private function createHandlerStack(): HandlerStack
    {
        $stack = HandlerStack::create();
        
        $stack->push(Middleware::retry(
            function ($retries, Request $request, ?Response $response, ?\Exception $e) {
                if ($retries >= $this->maxRetries) {
                    return false;
                }
                
                if ($response && $response->getStatusCode() >= 500) {
                    $this->logger->warning('PayPal API server error, retrying', [
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
                
                if ($e instanceof GuzzleException && str_contains($e->getMessage(), 'ECONNRESET')) {
                    return true;
                }
                
                return false;
            },
            function ($retries) {
                return (int)pow(2, $retries) * 100;
            }
        ));
        
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            $this->logger->debug('PayPal API request', [
                'method' => $request->getMethod(),
                'uri' => $request->getUri()->getPath(),
            ]);
            
            $request = $request->withHeader(
                'Authorization',
                'Bearer ' . $this->getAccessToken()
            );
            
            return $request;
        }));
        
        $stack->push(Middleware::mapResponse(function (Response $response) {
            $this->logger->debug('PayPal API response', [
                'status' => $response->getStatusCode(),
            ]);
            return $response;
        }));
        
        return $stack;
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiry > time()) {
            return $this->accessToken;
        }
        
        $response = (new Client([
            'base_uri' => $this->mode === 'live' 
                ? 'https://api.paypal.com/v1/' 
                : 'https://api.sandbox.paypal.com/v1/',
        ]))->post('oauth2/token', [
            'auth' => [$this->clientId, $this->clientSecret],
            'form_params' => ['grant_type' => 'client_credentials'],
        ]);
        
        $data = json_decode($response->getBody()->getContents(), true);
        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 60;
        
        return $this->accessToken;
    }

    private function generateRequestId(): string
    {
        return sprintf(
            '%s-%s-%s-%s',
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(4))
        );
    }

    public function createOrder(array $params): array
    {
        try {
            $response = $this->httpClient->post('checkout/orders', [
                'json' => $params,
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('PayPal order creation failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            throw new PaymentGatewayException(
                'Failed to create PayPal order: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function getOrder(string $orderId): array
    {
        try {
            $response = $this->httpClient->get('checkout/orders/' . $orderId);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('PayPal order retrieval failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentGatewayException(
                'Failed to get PayPal order: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function captureOrder(string $orderId): array
    {
        try {
            $response = $this->httpClient->post('checkout/orders/' . $orderId . '/capture');
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('PayPal order capture failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentGatewayException(
                'Failed to capture PayPal order: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function refundCapture(string $captureId, array $params = []): array
    {
        try {
            $response = $this->httpClient->post('payments/captures/' . $captureId . '/refund', [
                'json' => $params,
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('PayPal refund failed', [
                'capture_id' => $captureId,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentGatewayException(
                'Failed to process PayPal refund: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
