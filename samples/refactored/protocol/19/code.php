<?php
declare(strict_types=1);

namespace App\Soap;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use SoapClient;
use SoapFault;

abstract class AbstractSoapClient
{
    protected LoggerInterface $logger;
    protected SoapClient $client;
    protected string $wsdlUrl;
    protected string $apiKey;
    protected int $connectionTimeout = 30;
    protected int $responseTimeout = 60;

    abstract protected function getServiceName(): string;

    public function __construct(ConfigManager $config, LoggerInterface $logger)
    {
        $this->logger = $logger;
        
        $prefix = strtolower($this->getServiceName());
        $this->wsdlUrl = $config->get($prefix . '.soap.wsdl_url');
        $this->apiKey = $config->get($prefix . '.soap.api_key');
        
        $this->connectionTimeout = (int)$config->get($prefix . '.soap.connection_timeout', 30);
        $this->responseTimeout = (int)$config->get($prefix . '.soap.response_timeout', 60);
        
        $this->initializeClient();
    }

    protected function initializeClient(): void
    {
        $options = [
            'wsdl_cache' => WSDL_CACHE_BOTH,
            'connection_timeout' => $this->connectionTimeout,
            'timeout' => $this->responseTimeout,
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_BOTH,
            'uri' => 'http://' . strtolower($this->getServiceName()) . '.example.com/soap',
            'location' => $config->get(strtolower($this->getServiceName()) . '.soap.endpoint'),
            'soap_version' => SOAP_1_2,
            'encoding' => 'UTF-8',
        ];
        
        try {
            $this->client = new SoapClient($this->wsdlUrl, $options);
            $this->logger->info($this->getServiceName() . ' SOAP client initialized');
        } catch (SoapFault $e) {
            $this->logger->error($this->getServiceName() . ' SOAP client initialization failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function call(string $operation, array $params): ?array
    {
        try {
            $result = $this->client->{$operation}($params);
            
            $this->logger->debug($this->getServiceName() . ' SOAP ' . $operation . ' executed');
            
            return $this->normalizeResponse($operation, $result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, $operation, $params);
            return null;
        }
    }

    protected function normalizeResponse(string $operation, $result): ?array
    {
        return (array)$result;
    }

    protected function handleSoapFault(SoapFault $e, string $operation, array $params): void
    {
        $this->logger->error($this->getServiceName() . ' SOAP fault', [
            'operation' => $operation,
            'fault_code' => $e->faultcode,
            'fault_string' => $e->getMessage(),
            'params' => $params,
        ]);
    }

    protected function buildFiltersXml(array $filters): array
    {
        $xml = [];
        foreach ($filters as $key => $value) {
            $xml[ucfirst($key)] = $value;
        }
        return $xml;
    }

    protected function buildAddressXml(array $address): array
    {
        return [
            'Name' => $address['name'] ?? null,
            'Street' => $address['street'] ?? null,
            'City' => $address['city'],
            'State' => $address['state'] ?? null,
            'PostalCode' => $address['postal_code'],
            'Country' => $address['country'],
            'Phone' => $address['phone'] ?? null,
            'Email' => $address['email'] ?? null,
        ];
    }
}
