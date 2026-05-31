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

final class BraintreePaymentGateway
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private string $environment;
    private string $merchantId;
    private string $publicKey;
    private string $privateKey;
    private int $connectTimeout = 30;
    private int $timeout = 60;
    private int $maxRetries = 3;
    private ?string $accessToken = null;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->environment = $config->get('braintree.environment', 'sandbox');
        $this->merchantId = $config->get('braintree.merchant_id');
        $this->publicKey = $config->get('braintree.public_key');
        $this->privateKey = $config->get('braintree.private_key');
        
        $environmentUrls = [
            'sandbox' => 'https://api.sandbox.braintreegateway.com/services/transaction/v2',
            'production' => 'https://api.braintreegateway.com/services/transaction/v2',
        ];
        
        $this->httpClient = new Client([
            'base_uri' => $environmentUrls[$this->environment],
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'Braintree/PHP/' . PHP_VERSION,
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
                    $this->logger->warning('Braintree API server error, retrying', [
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
            },
            function ($retries) {
                return (int)pow(2, $retries) * 100;
            }
        ));
        
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            $this->logger->debug('Braintree API request', [
                'method' => $request->getMethod(),
                'uri' => $request->getUri()->getPath(),
            ]);
            
            $credentials = base64_encode($this->publicKey . ':' . $this->privateKey);
            $request = $request->withHeader('Authorization', 'Basic ' . $credentials);
            
            return $request;
        }));
        
        $stack->push(Middleware::mapResponse(function (Response $response) {
            $this->logger->debug('Braintree API response', [
                'status' => $response->getStatusCode(),
            ]);
            return $response;
        }));
        
        return $stack;
    }

    public function sale(array $params): array
    {
        try {
            $response = $this->httpClient->post('/', [
                'json' => [
                    'transaction' => array_merge($params, [
                        'type' => 'sale',
                    ]),
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['transaction'] ?? $data;
        } catch (GuzzleException $e) {
            $this->logger->error('Braintree sale failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            throw new PaymentGatewayException(
                'Failed to process Braintree sale: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function findTransaction(string $transactionId): array
    {
        try {
            $response = $this->httpClient->get('/' . $transactionId);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['transaction'] ?? $data;
        } catch (GuzzleException $e) {
            $this->logger->error('Braintree transaction lookup failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentGatewayException(
                'Failed to find Braintree transaction: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function refund(string $transactionId, ?string $amount = null): array
    {
        $params = ['transaction' => []];
        if ($amount !== null) {
            $params['transaction']['amount'] = $amount;
        }
        
        try {
            $response = $this->httpClient->post('/' . $transactionId . '/refund', [
                'json' => $params,
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['transaction'] ?? $data;
        } catch (GuzzleException $e) {
            $this->logger->error('Braintree refund failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentGatewayException(
                'Failed to process Braintree refund: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function voidTransaction(string $transactionId): array
    {
        try {
            $response = $this->httpClient->post('/' . $transactionId . '/void');
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['transaction'] ?? $data;
        } catch (GuzzleException $e) {
            $this->logger->error('Braintree void failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentGatewayException(
                'Failed to void Braintree transaction: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
