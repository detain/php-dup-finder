<?php
declare(strict_types=1);

namespace App\Shipping\Carriers;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

final class FedExCarrierClient
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private string $apiKey;
    private string $apiSecret;
    private string $accountNumber;
    private string $meterNumber;
    private bool $isProduction;
    private int $connectTimeout = 30;
    private int $timeout = 60;
    private int $maxRetries = 3;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->apiKey = $config->get('fedex.api_key');
        $this->apiSecret = $config->get('fedex.api_secret');
        $this->accountNumber = $config->get('fedex.account_number');
        $this->meterNumber = $config->get('fedex.meter_number');
        $this->isProduction = $config->get('fedex.environment') === 'production';
        
        $baseUri = $this->isProduction 
            ? 'https://apis.fedex.com/'
            : 'https://apis-sandbox.fedex.com/';
        
        $this->httpClient = new Client([
            'base_uri' => $baseUri,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Api-Key' => $this->apiKey,
                'X-Api-Secret' => $this->apiSecret,
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
                    $this->logger->warning('FedEx API server error, retrying', [
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
            },
            function ($retries) {
                return (int)pow(2, $retries) * 100;
            }
        ));
        
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            $this->logger->debug('FedEx API request', [
                'method' => $request->getMethod(),
                'uri' => $request->getUri()->getPath(),
            ]);
            return $request;
        }));
        
        $stack->push(Middleware::mapResponse(function (Response $response) {
            $this->logger->debug('FedEx API response', [
                'status' => $response->getStatusCode(),
            ]);
            return $response;
        }));
        
        return $stack;
    }

    public function getRates(array $shipmentData): array
    {
        try {
            $response = $this->httpClient->post('ship/v1/rates', [
                'json' => [
                    'requestedShipment' => $shipmentData,
                    'accountNumber' => [
                        'value' => $this->accountNumber,
                    ],
                ],
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('FedEx rates request failed', [
                'error' => $e->getMessage(),
            ]);
            throw new CarrierException('FedEx rates request failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function createShipment(array $shipmentData): array
    {
        try {
            $response = $this->httpClient->post('ship/v1/shipments', [
                'json' => array_merge($shipmentData, [
                    'accountNumber' => [
                        'value' => $this->accountNumber,
                    ],
                ]),
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('FedEx shipment creation failed', [
                'error' => $e->getMessage(),
            ]);
            throw new CarrierException('FedEx shipment creation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function trackPackage(string $trackingNumber): array
    {
        try {
            $response = $this->httpClient->post('track/v1/trackingnumbers', [
                'json' => [
                    'includeDetailedScans' => true,
                    'trackingInfo' => [
                        [
                            'trackingNumberInfo' => [
                                'trackingNumber' => $trackingNumber,
                            ],
                        ],
                    ],
                ],
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('FedEx tracking request failed', [
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage(),
            ]);
            throw new CarrierException('FedEx tracking request failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function voidShipment(string $trackingNumber): bool
    {
        try {
            $response = $this->httpClient->delete('ship/v1/shipments/' . $trackingNumber);
            
            return $response->getStatusCode() === 200;
            
        } catch (GuzzleException $e) {
            $this->logger->error('FedEx shipment void failed', [
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function validateAddress(array $address): array
    {
        try {
            $response = $this->httpClient->post('address/v1/addresses/resolve', [
                'json' => [
                    'address' => $address,
                    'accountNumber' => [
                        'value' => $this->accountNumber,
                    ],
                ],
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('FedEx address validation failed', [
                'error' => $e->getMessage(),
            ]);
            throw new CarrierException('FedEx address validation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }
}
