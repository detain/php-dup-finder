<?php
declare(strict_types=1);

namespace App\Tax\Soap;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use SoapClient;
use SoapFault;

final class TaxSoapClient
{
    private LoggerInterface $logger;
    private SoapClient $client;
    private string $wsdlUrl;
    private string $apiKey;
    private array $defaultHeaders = [];
    private int $connectionTimeout = 30;
    private int $responseTimeout = 60;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->wsdlUrl = $config->get('tax.soap.wsdl_url');
        $this->apiKey = $config->get('tax.soap.api_key');
        
        $this->connectionTimeout = (int)$config->get('tax.soap.connection_timeout', 30);
        $this->responseTimeout = (int)$config->get('tax.soap.response_timeout', 60);
        
        $this->initializeClient();
    }

    private function initializeClient(): void
    {
        $options = [
            'wsdl_cache' => WSDL_CACHE_BOTH,
            'connection_timeout' => $this->connectionTimeout,
            'timeout' => $this->responseTimeout,
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_BOTH,
            'uri' => 'http://tax.example.com/soap',
            'location' => $config->get('tax.soap.endpoint'),
            'soap_version' => SOAP_1_2,
            'encoding' => 'UTF-8',
        ];
        
        try {
            $this->client = new SoapClient($this->wsdlUrl, $options);
            $this->logger->info('Tax SOAP client initialized');
        } catch (SoapFault $e) {
            $this->logger->error('Tax SOAP client initialization failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function calculateTax(string $country, string $region, string $postalCode, float $amount, string $taxCode): ?array
    {
        try {
            $result = $this->client->CalculateTax([
                'Address' => [
                    'Country' => $country,
                    'Region' => $region,
                    'PostalCode' => $postalCode,
                ],
                'LineItem' => [
                    'Amount' => $amount,
                    'TaxCode' => $taxCode,
                ],
            ]);
            
            $this->logger->debug('Tax SOAP CalculateTax executed', [
                'country' => $country,
                'region' => $region,
                'amount' => $amount,
            ]);
            
            return $this->normalizeTaxResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'CalculateTax', ['country' => $country]);
            return null;
        }
    }

    public function calculateTaxBatch(array $transactions): array
    {
        try {
            $result = $this->client->CalculateTaxBatch([
                'Transactions' => array_map(function ($tx) {
                    return [
                        'TransactionId' => $tx['id'],
                        'Address' => [
                            'Country' => $tx['country'],
                            'Region' => $tx['region'] ?? null,
                            'PostalCode' => $tx['postal_code'],
                        ],
                        'LineItems' => [
                            'LineItem' => array_map(function ($item) {
                                return [
                                    'Amount' => $item['amount'],
                                    'TaxCode' => $item['tax_code'],
                                    'Quantity' => $item['quantity'] ?? 1,
                                ];
                            }, $tx['items']),
                        ],
                    ];
                }, $transactions),
            ]);
            
            $this->logger->debug('Tax SOAP CalculateTaxBatch executed', [
                'transaction_count' => count($transactions),
            ]);
            
            return $this->normalizeTaxBatchResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'CalculateTaxBatch', ['count' => count($transactions)]);
            return [];
        }
    }

    public function getTaxRates(string $country, ?string $region = null): array
    {
        try {
            $params = ['Country' => $country];
            if ($region !== null) {
                $params['Region'] = $region;
            }
            
            $result = $this->client->GetTaxRates($params);
            
            $this->logger->debug('Tax SOAP GetTaxRates executed', [
                'country' => $country,
                'region' => $region,
            ]);
            
            return $this->normalizeTaxRatesResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'GetTaxRates', ['country' => $country]);
            return [];
        }
    }

    public function getExemptionCertificate(string $customerId): ?array
    {
        try {
            $result = $this->client->GetExemptionCertificate([
                'CustomerId' => $customerId,
            ]);
            
            $this->logger->debug('Tax SOAP GetExemptionCertificate executed', [
                'customer_id' => $customerId,
            ]);
            
            return $this->normalizeCertificateResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'GetExemptionCertificate', ['customer_id' => $customerId]);
            return null;
        }
    }

    public function applyExemption(string $customerId, string $certificateId): bool
    {
        try {
            $this->client->ApplyExemption([
                'CustomerId' => $customerId,
                'CertificateId' => $certificateId,
            ]);
            
            $this->logger->info('Tax SOAP ApplyExemption executed', [
                'customer_id' => $customerId,
                'certificate_id' => $certificateId,
            ]);
            
            return true;
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'ApplyExemption', ['customer_id' => $customerId]);
            return false;
        }
    }

    public function commitTax(string $documentId, string $type): ?array
    {
        try {
            $result = $this->client->CommitTax([
                'DocumentId' => $documentId,
                'DocumentType' => $type,
            ]);
            
            $this->logger->info('Tax SOAP CommitTax executed', [
                'document_id' => $documentId,
                'type' => $type,
            ]);
            
            return $this->normalizeCommitResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'CommitTax', ['document_id' => $documentId]);
            return null;
        }
    }

    private function normalizeTaxResponse($result): ?array
    {
        if (!isset($result->TaxResult)) {
            return null;
        }
        
        $tax = $result->TaxResult;
        
        return [
            'taxable_amount' => (float)$tax->TaxableAmount,
            'tax_amount' => (float)$tax->TaxAmount,
            'tax_rate' => (float)$tax->TaxRate,
            'jurisdiction' => (string)$tax->Jurisdiction->Name ?? null,
            'breakdown' => [
                'state' => (float)$tax->Breakdown->StateTax ?? 0,
                'county' => (float)$tax->Breakdown->CountyTax ?? 0,
                'city' => (float)$tax->Breakdown->CityTax ?? 0,
                'special' => (float)$tax->Breakdown->SpecialTax ?? 0,
            ],
        ];
    }

    private function normalizeTaxBatchResponse($result): array
    {
        $results = [];
        
        if (isset($result->Results) && isset($result->Results->TaxResult)) {
            $items = is_array($result->Results->TaxResult) 
                ? $result->Results->TaxResult 
                : [$result->Results->TaxResult];
            
            foreach ($items as $item) {
                $results[(string)$item->TransactionId] = [
                    'taxable_amount' => (float)$item->TaxableAmount,
                    'tax_amount' => (float)$item->TaxAmount,
                ];
            }
        }
        
        return $results;
    }

    private function normalizeTaxRatesResponse($result): array
    {
        $rates = [];
        
        if (isset($result->Rates) && isset($result->Rates->Rate)) {
            $items = is_array($result->Rates->Rate) 
                ? $result->Rates->Rate 
                : [$result->Rates->Rate];
            
            foreach ($items as $rate) {
                $rates[] = [
                    'region' => (string)$rate->Region ?? null,
                    'postal_code' => (string)$rate->PostalCode ?? null,
                    'rate' => (float)$rate->Rate,
                    'name' => (string)$rate->Name ?? null,
                    'type' => (string)$rate->Type ?? null,
                ];
            }
        }
        
        return $rates;
    }

    private function normalizeCertificateResponse($result): ?array
    {
        if (!isset($result->Certificate)) {
            return null;
        }
        
        $cert = $result->Certificate;
        
        return [
            'id' => (string)$cert->CertificateId,
            'customer_id' => (string)$cert->CustomerId,
            'state' => (string)$cert->State,
            'issuing jurisdiction' => (string)$cert->IssuingJurisdiction ?? null,
            'exemption_reason' => (string)$cert->ExemptionReason ?? null,
            'expires_at' => (string)$cert->ExpirationDate ?? null,
        ];
    }

    private function normalizeCommitResponse($result): ?array
    {
        if (!isset($result->CommitResult)) {
            return null;
        }
        
        $commit = $result->CommitResult;
        
        return [
            'document_id' => (string)$commit->DocumentId,
            'total_tax' => (float)$commit->TotalTax,
            'status' => (string)$commit->Status,
            'committed_at' => (string)$commit->CommittedDate ?? null,
        ];
    }

    private function handleSoapFault(SoapFault $e, string $operation, array $context = []): void
    {
        $this->logger->error('Tax SOAP fault', [
            'operation' => $operation,
            'fault_code' => $e->faultcode,
            'fault_string' => $e->getMessage(),
            'context' => $context,
        ]);
    }
}
