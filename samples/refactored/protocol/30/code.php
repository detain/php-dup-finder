<?php
declare(strict_types=1);

namespace App\Services\Soap;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use SoapClient;

abstract class AbstractSoapClient
{
    protected ConfigManager $config;
    protected LoggerInterface $logger;
    protected ?SoapClient $client = null;
    protected array $wsdlCache = [];
    protected int $cacheExpiry = 3600;

    abstract protected function getWsdlUrl(): string;
    abstract protected function getServiceName(): string;

    public function call(string $method, array $arguments = []): array
    {
        $client = $this->getClient();
        
        try {
            $this->logger->debug($this->getServiceName() . ' SOAP call', [
                'method' => $method,
                'arguments' => array_keys($arguments),
            ]);
            
            $result = $client->__soapCall($method, [$arguments]);
            
            $this->logger->info($this->getServiceName() . ' SOAP call successful', [
                'method' => $method,
            ]);
            
            return $this->parseResult($result);
            
        } catch (\Exception $e) {
            $this->logger->error($this->getServiceName() . ' SOAP call failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function getClient(): SoapClient
    {
        if ($this->client === null) {
            $wsdlUrl = $this->getWsdlUrl();
            $this->client = $this->createClient($wsdlUrl);
        }
        
        return $this->client;
    }

    protected function createClient(string $wsdlUrl): SoapClient
    {
        $cachedWsdl = $this->getCachedWsdl($wsdlUrl);
        
        if ($cachedWsdl !== null) {
            $this->logger->debug('Using cached WSDL for ' . $this->getServiceName(), [
                'url' => $wsdlUrl,
            ]);
            
            return new SoapClient($cachedWsdl, [
                'cache_wsdl' => WSDL_CACHE_NONE,
                'trace' => true,
                'exceptions' => true,
            ]);
        }
        
        $this->logger->debug('Fetching and caching WSDL for ' . $this->getServiceName(), [
            'url' => $wsdlUrl,
        ]);
        
        $this->cacheWsdl($wsdlUrl);
        
        return new SoapClient($this->getCachedWsdl($wsdlUrl), [
            'cache_wsdl' => WSDL_CACHE_NONE,
            'trace' => true,
            'exceptions' => true,
        ]);
    }

    protected function getCachedWsdl(string $url): ?string
    {
        $cacheKey = md5($url);
        
        if (!isset($this->wsdlCache[$cacheKey])) {
            return null;
        }
        
        if (time() > $this->wsdlCache[$cacheKey]['expiry']) {
            unset($this->wsdlCache[$cacheKey]);
            return null;
        }
        
        return $this->wsdlCache[$cacheKey]['path'];
    }

    protected function cacheWsdl(string $url): void
    {
        $cacheKey = md5($url);
        $cacheDir = $this->config->get('app.cache_dir') . '/wsdl';
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $cachedPath = $cacheDir . '/' . $cacheKey . '.wsdl';
        
        $wsdlContent = file_get_contents($url);
        
        if ($wsdlContent !== false) {
            file_put_contents($cachedPath, $wsdlContent);
            
            $this->wsdlCache[$cacheKey] = [
                'path' => $cachedPath,
                'expiry' => time() + $this->cacheExpiry,
            ];
        }
    }

    protected function parseResult($result): array
    {
        if ($result instanceof \SoapFault) {
            throw new \RuntimeException($result->getMessage());
        }
        
        return json_decode(json_encode($result), true) ?? [];
    }

    public function clearCache(): void
    {
        $this->wsdlCache = [];
        $this->client = null;
        
        $this->logger->info($this->getServiceName() . ' SOAP cache cleared');
    }
}
