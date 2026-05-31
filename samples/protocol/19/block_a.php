<?php
declare(strict_types=1);

namespace App\Billing\Soap;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use SoapClient;
use SoapFault;

final class BillingSoapClient
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
        $this->wsdlUrl = $config->get('billing.soap.wsdl_url');
        $this->apiKey = $config->get('billing.soap.api_key');
        
        $this->connectionTimeout = (int)$config->get('billing.soap.connection_timeout', 30);
        $this->responseTimeout = (int)$config->get('billing.soap.response_timeout', 60);
        
        $this->defaultHeaders = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'text/xml; charset=utf-8',
        ];
        
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
            'uri' => 'http://billing.example.com/soap',
            'location' => $config->get('billing.soap.endpoint'),
            'soap_version' => SOAP_1_2,
            'encoding' => 'UTF-8',
        ];
        
        try {
            $this->client = new SoapClient($this->wsdlUrl, $options);
            $this->logger->info('Billing SOAP client initialized');
        } catch (SoapFault $e) {
            $this->logger->error('Billing SOAP client initialization failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getInvoice(string $invoiceId): ?array
    {
        try {
            $result = $this->client->GetInvoice([
                'InvoiceId' => $invoiceId,
            ]);
            
            $this->logger->debug('Billing SOAP GetInvoice executed', [
                'invoice_id' => $invoiceId,
            ]);
            
            return $this->normalizeInvoiceResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'GetInvoice', ['invoice_id' => $invoiceId]);
            return null;
        }
    }

    public function getInvoices(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        try {
            $result = $this->client->GetInvoices([
                'Filters' => $this->buildFiltersXml($filters),
                'Pagination' => [
                    'Page' => $page,
                    'PageSize' => $perPage,
                ],
            ]);
            
            $this->logger->debug('Billing SOAP GetInvoices executed', [
                'filters' => $filters,
                'page' => $page,
            ]);
            
            return $this->normalizeInvoicesResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'GetInvoices', ['filters' => $filters]);
            return ['data' => [], 'pagination' => []];
        }
    }

    public function createInvoice(array $invoiceData): ?array
    {
        try {
            $result = $this->client->CreateInvoice([
                'Invoice' => $this->buildInvoiceXml($invoiceData),
            ]);
            
            $this->logger->info('Billing SOAP CreateInvoice executed', [
                'invoice_number' => $invoiceData['invoice_number'] ?? null,
            ]);
            
            return $this->normalizeInvoiceResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'CreateInvoice', $invoiceData);
            return null;
        }
    }

    public function updateInvoice(string $invoiceId, array $invoiceData): ?array
    {
        try {
            $result = $this->client->UpdateInvoice([
                'InvoiceId' => $invoiceId,
                'Invoice' => $this->buildInvoiceXml($invoiceData),
            ]);
            
            $this->logger->info('Billing SOAP UpdateInvoice executed', [
                'invoice_id' => $invoiceId,
            ]);
            
            return $this->normalizeInvoiceResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'UpdateInvoice', ['invoice_id' => $invoiceId]);
            return null;
        }
    }

    public function deleteInvoice(string $invoiceId): bool
    {
        try {
            $this->client->DeleteInvoice([
                'InvoiceId' => $invoiceId,
            ]);
            
            $this->logger->info('Billing SOAP DeleteInvoice executed', [
                'invoice_id' => $invoiceId,
            ]);
            
            return true;
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'DeleteInvoice', ['invoice_id' => $invoiceId]);
            return false;
        }
    }

    public function processPayment(string $invoiceId, array $paymentData): ?array
    {
        try {
            $result = $this->client->ProcessPayment([
                'InvoiceId' => $invoiceId,
                'Payment' => $this->buildPaymentXml($paymentData),
            ]);
            
            $this->logger->info('Billing SOAP ProcessPayment executed', [
                'invoice_id' => $invoiceId,
                'amount' => $paymentData['amount'] ?? null,
            ]);
            
            return $this->normalizePaymentResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'ProcessPayment', ['invoice_id' => $invoiceId]);
            return null;
        }
    }

    public function refundPayment(string $paymentId, ?float $amount = null): ?array
    {
        try {
            $params = ['PaymentId' => $paymentId];
            if ($amount !== null) {
                $params['Amount'] = $amount;
            }
            
            $result = $this->client->RefundPayment($params);
            
            $this->logger->info('Billing SOAP RefundPayment executed', [
                'payment_id' => $paymentId,
                'amount' => $amount,
            ]);
            
            return $this->normalizeRefundResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'RefundPayment', ['payment_id' => $paymentId]);
            return null;
        }
    }

    public function getPaymentMethods(string $customerId): array
    {
        try {
            $result = $this->client->GetPaymentMethods([
                'CustomerId' => $customerId,
            ]);
            
            $this->logger->debug('Billing SOAP GetPaymentMethods executed', [
                'customer_id' => $customerId,
            ]);
            
            return $this->normalizePaymentMethodsResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'GetPaymentMethods', ['customer_id' => $customerId]);
            return [];
        }
    }

    public function addPaymentMethod(string $customerId, array $methodData): ?array
    {
        try {
            $result = $this->client->AddPaymentMethod([
                'CustomerId' => $customerId,
                'PaymentMethod' => $this->buildPaymentMethodXml($methodData),
            ]);
            
            $this->logger->info('Billing SOAP AddPaymentMethod executed', [
                'customer_id' => $customerId,
            ]);
            
            return $this->normalizePaymentMethodResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'AddPaymentMethod', ['customer_id' => $customerId]);
            return null;
        }
    }

    private function buildFiltersXml(array $filters): array
    {
        $xml = [];
        foreach ($filters as $key => $value) {
            $xml[ucfirst($key)] = $value;
        }
        return $xml;
    }

    private function buildInvoiceXml(array $invoiceData): array
    {
        return [
            'InvoiceNumber' => $invoiceData['invoice_number'] ?? null,
            'CustomerId' => $invoiceData['customer_id'],
            'Amount' => $invoiceData['amount'],
            'Currency' => $invoiceData['currency'] ?? 'USD',
            'DueDate' => $invoiceData['due_date'] ?? null,
            'Items' => isset($invoiceData['items']) 
                ? array_map([$this, 'buildLineItemXml'], $invoiceData['items']) 
                : null,
        ];
    }

    private function buildLineItemXml(array $item): array
    {
        return [
            'Description' => $item['description'],
            'Quantity' => $item['quantity'],
            'UnitPrice' => $item['unit_price'],
            'TaxCode' => $item['tax_code'] ?? null,
        ];
    }

    private function buildPaymentXml(array $paymentData): array
    {
        return [
            'Method' => $paymentData['method'],
            'Amount' => $paymentData['amount'],
            'Currency' => $paymentData['currency'] ?? 'USD',
            'Reference' => $paymentData['reference'] ?? null,
        ];
    }

    private function buildPaymentMethodXml(array $methodData): array
    {
        return [
            'Type' => $methodData['type'],
            'CardNumber' => $methodData['card_number'] ?? null,
            'ExpiryMonth' => $methodData['expiry_month'] ?? null,
            'ExpiryYear' => $methodData['expiry_year'] ?? null,
            'Cvv' => $methodData['cvv'] ?? null,
            'BillingAddress' => isset($methodData['billing_address']) 
                ? $this->buildAddressXml($methodData['billing_address']) 
                : null,
        ];
    }

    private function buildAddressXml(array $address): array
    {
        return [
            'Street' => $address['street'] ?? null,
            'City' => $address['city'],
            'State' => $address['state'] ?? null,
            'PostalCode' => $address['postal_code'],
            'Country' => $address['country'],
        ];
    }

    private function normalizeInvoiceResponse($result): ?array
    {
        if (!isset($result->Invoice)) {
            return null;
        }
        
        $invoice = $result->Invoice;
        
        return [
            'id' => (string)$invoice->InvoiceId,
            'number' => (string)$invoice->InvoiceNumber,
            'customer_id' => (string)$invoice->CustomerId,
            'amount' => (float)$invoice->Amount,
            'currency' => (string)$invoice->Currency,
            'status' => (string)$invoice->Status,
            'due_date' => (string)$invoice->DueDate ?? null,
            'created_at' => (string)$invoice->CreatedDate ?? null,
        ];
    }

    private function normalizeInvoicesResponse($result): array
    {
        $invoices = [];
        
        if (isset($result->Invoices) && isset($result->Invoices->Invoice)) {
            foreach ($result->Invoices->Invoice as $invoice) {
                $invoices[] = $this->normalizeInvoiceResponse((object)['Invoice' => $invoice]);
            }
        }
        
        $pagination = isset($result->Pagination) ? [
            'total' => (int)$result->Pagination->TotalRecords,
            'page' => (int)$result->Pagination->Page,
            'per_page' => (int)$result->Pagination->PageSize,
        ] : [];
        
        return [
            'data' => $invoices,
            'pagination' => $pagination,
        ];
    }

    private function normalizePaymentResponse($result): ?array
    {
        if (!isset($result->Payment)) {
            return null;
        }
        
        $payment = $result->Payment;
        
        return [
            'id' => (string)$payment->PaymentId,
            'invoice_id' => (string)$payment->InvoiceId,
            'amount' => (float)$payment->Amount,
            'status' => (string)$payment->Status,
            'processed_at' => (string)$payment->ProcessedDate ?? null,
        ];
    }

    private function normalizeRefundResponse($result): ?array
    {
        if (!isset($result->Refund)) {
            return null;
        }
        
        $refund = $result->Refund;
        
        return [
            'id' => (string)$refund->RefundId,
            'payment_id' => (string)$refund->PaymentId,
            'amount' => (float)$refund->Amount,
            'status' => (string)$refund->Status,
            'processed_at' => (string)$refund->ProcessedDate ?? null,
        ];
    }

    private function normalizePaymentMethodsResponse($result): array
    {
        $methods = [];
        
        if (isset($result->PaymentMethods) && isset($result->PaymentMethods->PaymentMethod)) {
            $items = is_array($result->PaymentMethods->PaymentMethod) 
                ? $result->PaymentMethods->PaymentMethod 
                : [$result->PaymentMethods->PaymentMethod];
            
            foreach ($items as $method) {
                $methods[] = [
                    'id' => (string)$method->MethodId,
                    'type' => (string)$method->Type,
                    'last_four' => (string)$method->LastFour ?? null,
                    'expiry_month' => (int)$method->ExpiryMonth ?? null,
                    'expiry_year' => (int)$method->ExpiryYear ?? null,
                    'is_default' => (bool)$method->IsDefault ?? false,
                ];
            }
        }
        
        return $methods;
    }

    private function normalizePaymentMethodResponse($result): ?array
    {
        if (!isset($result->PaymentMethod)) {
            return null;
        }
        
        $method = $result->PaymentMethod;
        
        return [
            'id' => (string)$method->MethodId,
            'type' => (string)$method->Type,
            'last_four' => (string)$method->LastFour ?? null,
            'expiry_month' => (int)$method->ExpiryMonth ?? null,
            'expiry_year' => (int)$method->ExpiryYear ?? null,
        ];
    }

    private function handleSoapFault(SoapFault $e, string $operation, array $context = []): void
    {
        $this->logger->error('Billing SOAP fault', [
            'operation' => $operation,
            'fault_code' => $e->faultcode,
            'fault_string' => $e->getMessage(),
            'context' => $context,
        ]);
    }
}
