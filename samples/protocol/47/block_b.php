<?php

declare(strict_types=1);

namespace App\Services\Soap;

use App\Contracts\SoapClientInterface;
use SoapClient;
use SoapFault;

class AppSoapClient implements SoapClientInterface
{
    private string $endpoint;
    private array $authCredentials;
    private int $timeout;
    private array $defaultHeaders;

    public function __construct(
        string $endpoint,
        array $authCredentials,
        int $timeout = 30
    ) {
        $this->endpoint = $endpoint;
        $this->authCredentials = $authCredentials;
        $this->timeout = $timeout;
        $this->defaultHeaders = [
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '',
        ];
    }

    public function call(string $service, string $action, array $params): array
    {
        $client = $this->createClient();

        try {
            $result = $client->__soapCall($action, [$this->wrapParams($service, $action, $params)]);

            return $this->parseResponse($result);
        } catch (SoapFault $e) {
            throw new \RuntimeException(
                "SOAP call failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    public function callUserService(string $action, array $params): array
    {
        return $this->call('UserService', $action, $params);
    }

    public function callOrderService(string $action, array $params): array
    {
        return $this->call('OrderService', $action, $params);
    }

    public function callProductService(string $action, array $params): array
    {
        return $this->call('ProductService', $action, $params);
    }

    private function createClient(): SoapClient
    {
        $options = [
            'location' => $this->endpoint,
            'uri' => 'http://schemas.xmlsoap.org/soap/envelope/',
            'soap_version' => SOAP_1_2,
            'encoding' => 'UTF-8',
            'connection_timeout' => $this->timeout,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'trace' => true,
            'exceptions' => true,
        ];

        return new SoapClient(null, $options);
    }

    private function wrapParams(string $service, string $action, array $params): array
    {
        return array_merge($params, [
            'auth' => $this->authCredentials,
            'service' => $service,
            'action' => $action,
            'timestamp' => time(),
        ]);
    }

    private function parseResponse($result): array
    {
        if ($result instanceof \stdClass) {
            return json_decode(json_encode($result), true);
        }

        return (array) $result;
    }

    public function setAuthCredentials(array $credentials): void
    {
        $this->authCredentials = $credentials;
    }

    public function getLastRequest(): string
    {
        return $this->client->__getLastRequest() ?? '';
    }

    public function getLastResponse(): string
    {
        return $this->client->__getLastResponse() ?? '';
    }
}
