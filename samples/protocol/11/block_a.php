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

final class StripePaymentGateway
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private string $apiKey;
    private string $apiVersion = '2023-10-16';
    private int $connectTimeout = 30;
    private int $timeout = 60;
    private int $maxRetries = 3;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->apiKey = $config->get('stripe.secret_key');
        
        $this->httpClient = new Client([
            'base_uri' => 'https://api.stripe.com/v1/',
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Stripe-Version' => $this->apiVersion,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'Stripe/PHP/' . PHP_VERSION,
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
                    $this->logger->warning('Stripe API server error, retrying', [
                        'retry' => $retries + 1,
                        'max_retries' => $this->maxRetries,
                        'status' => $response->getStatusCode(),
                    ]);
                    return true;
                }
                
                if ($e instanceof GuzzleException && $e->getCode() === 429) {
                    $retryAfter = $response->getHeaderLine('Retry-After');
                    if ($retryAfter) {
                        sleep((int)$retryAfter);
                    }
                    return true;
                }
                
                return false;
            },
            function ($retries) {
                return (int)pow(2, $retries) * 100;
            }
        ));
        
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            $this->logger->debug('Stripe API request', [
                'method' => $request->getMethod(),
                'uri' => $request->getUri()->getPath(),
            ]);
            return $request;
        }));
        
        $stack->push(Middleware::mapResponse(function (Response $response) {
            $this->logger->debug('Stripe API response', [
                'status' => $response->getStatusCode(),
            ]);
            return $response;
        }));
        
        return $stack;
    }

    public function createPaymentIntent(array $params): array
    {
        try {
            $response = $this->httpClient->post('payment_intents', [
                'form_params' => $params,
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('Stripe payment intent creation failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            throw new PaymentGatewayException(
                'Failed to create payment intent: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        try {
            $response = $this->httpClient->get('payment_intents/' . $paymentIntentId);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('Stripe payment intent retrieval failed', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentGatewayException(
                'Failed to retrieve payment intent: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function confirmPaymentIntent(string $paymentIntentId, array $params = []): array
    {
        try {
            $response = $this->httpClient->post('payment_intents/' . $paymentIntentId . '/confirm', [
                'form_params' => $params,
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('Stripe payment intent confirmation failed', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentGatewayException(
                'Failed to confirm payment intent: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function refund(string $paymentIntentId, ?int $amount = null): array
    {
        $params = ['payment_intent' => $paymentIntentId];
        if ($amount !== null) {
            $params['amount'] = $amount;
        }
        
        try {
            $response = $this->httpClient->post('refunds', [
                'form_params' => $params,
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('Stripe refund failed', [
                'payment_intent_id' => $paymentIntentId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentGatewayException(
                'Failed to process refund: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
