<?php
declare(strict_types=1);

namespace App\Shipping\Soap;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use SoapClient;
use SoapFault;

final class ShippingSoapClient
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
        $this->wsdlUrl = $config->get('shipping.soap.wsdl_url');
        $this->apiKey = $config->get('shipping.soap.api_key');
        
        $this->connectionTimeout = (int)$config->get('shipping.soap.connection_timeout', 30);
        $this->responseTimeout = (int)$config->get('shipping.soap.response_timeout', 60);
        
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
            'uri' => 'http://shipping.example.com/soap',
            'location' => $config->get('shipping.soap.endpoint'),
            'soap_version' => SOAP_1_2,
            'encoding' => 'UTF-8',
        ];
        
        try {
            $this->client = new SoapClient($this->wsdlUrl, $options);
            $this->logger->info('Shipping SOAP client initialized');
        } catch (SoapFault $e) {
            $this->logger->error('Shipping SOAP client initialization failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getShipment(string $shipmentId): ?array
    {
        try {
            $result = $this->client->GetShipment([
                'ShipmentId' => $shipmentId,
            ]);
            
            $this->logger->debug('Shipping SOAP GetShipment executed', [
                'shipment_id' => $shipmentId,
            ]);
            
            return $this->normalizeShipmentResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'GetShipment', ['shipment_id' => $shipmentId]);
            return null;
        }
    }

    public function getShipments(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        try {
            $result = $this->client->GetShipments([
                'Filters' => $this->buildFiltersXml($filters),
                'Pagination' => [
                    'Page' => $page,
                    'PageSize' => $perPage,
                ],
            ]);
            
            $this->logger->debug('Shipping SOAP GetShipments executed', [
                'filters' => $filters,
                'page' => $page,
            ]);
            
            return $this->normalizeShipmentsResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'GetShipments', ['filters' => $filters]);
            return ['data' => [], 'pagination' => []];
        }
    }

    public function createShipment(array $shipmentData): ?array
    {
        try {
            $result = $this->client->CreateShipment([
                'Shipment' => $this->buildShipmentXml($shipmentData),
            ]);
            
            $this->logger->info('Shipping SOAP CreateShipment executed', [
                'order_id' => $shipmentData['order_id'] ?? null,
            ]);
            
            return $this->normalizeShipmentResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'CreateShipment', $shipmentData);
            return null;
        }
    }

    public function cancelShipment(string $shipmentId, string $reason): bool
    {
        try {
            $this->client->CancelShipment([
                'ShipmentId' => $shipmentId,
                'Reason' => $reason,
            ]);
            
            $this->logger->info('Shipping SOAP CancelShipment executed', [
                'shipment_id' => $shipmentId,
                'reason' => $reason,
            ]);
            
            return true;
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'CancelShipment', ['shipment_id' => $shipmentId]);
            return false;
        }
    }

    public function getRates(string $fromZip, string $toZip, array $packageData): array
    {
        try {
            $result = $this->client->GetRates([
                'FromAddress' => [
                    'PostalCode' => $fromZip,
                    'Country' => $packageData['from_country'] ?? 'US',
                ],
                'ToAddress' => [
                    'PostalCode' => $toZip,
                    'Country' => $packageData['to_country'] ?? 'US',
                ],
                'Package' => [
                    'Weight' => $packageData['weight'],
                    'WeightUnit' => $packageData['weight_unit'] ?? 'LB',
                    'Dimensions' => [
                        'Length' => $packageData['length'] ?? 0,
                        'Width' => $packageData['width'] ?? 0,
                        'Height' => $packageData['height'] ?? 0,
                        'Unit' => $packageData['dimension_unit'] ?? 'IN',
                    ],
                ],
            ]);
            
            $this->logger->debug('Shipping SOAP GetRates executed', [
                'from_zip' => $fromZip,
                'to_zip' => $toZip,
            ]);
            
            return $this->normalizeRatesResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'GetRates', ['from_zip' => $fromZip, 'to_zip' => $toZip]);
            return [];
        }
    }

    public function getTrackingInfo(string $trackingNumber): ?array
    {
        try {
            $result = $this->client->GetTrackingInfo([
                'TrackingNumber' => $trackingNumber,
            ]);
            
            $this->logger->debug('Shipping SOAP GetTrackingInfo executed', [
                'tracking_number' => $trackingNumber,
            ]);
            
            return $this->normalizeTrackingResponse($result);
        } catch (SoapFault $e) {
            $this->handleSoapFault($e, 'GetTrackingInfo', ['tracking_number' => $trackingNumber]);
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

    private function buildShipmentXml(array $shipmentData): array
    {
        return [
            'OrderId' => $shipmentData['order_id'],
            'Carrier' => $shipmentData['carrier'],
            'Service' => $shipmentData['service'],
            'FromAddress' => $this->buildAddressXml($shipmentData['from_address']),
            'ToAddress' => $this->buildAddressXml($shipmentData['to_address']),
            'Package' => $this->buildPackageXml($shipmentData['package']),
        ];
    }

    private function buildAddressXml(array $address): array
    {
        return [
            'Name' => $address['name'],
            'Street' => $address['street'] ?? null,
            'City' => $address['city'],
            'State' => $address['state'] ?? null,
            'PostalCode' => $address['postal_code'],
            'Country' => $address['country'],
            'Phone' => $address['phone'] ?? null,
            'Email' => $address['email'] ?? null,
        ];
    }

    private function buildPackageXml(array $package): array
    {
        return [
            'Weight' => $package['weight'],
            'WeightUnit' => $package['weight_unit'] ?? 'LB',
            'Dimensions' => [
                'Length' => $package['length'] ?? 0,
                'Width' => $package['width'] ?? 0,
                'Height' => $package['height'] ?? 0,
                'Unit' => $package['dimension_unit'] ?? 'IN',
            ],
        ];
    }

    private function normalizeShipmentResponse($result): ?array
    {
        if (!isset($result->Shipment)) {
            return null;
        }
        
        $shipment = $result->Shipment;
        
        return [
            'id' => (string)$shipment->ShipmentId,
            'order_id' => (string)$shipment->OrderId,
            'tracking_number' => (string)$shipment->TrackingNumber ?? null,
            'carrier' => (string)$shipment->Carrier,
            'service' => (string)$shipment->Service,
            'status' => (string)$shipment->Status,
            'shipped_at' => (string)$shipment->ShippedDate ?? null,
            'estimated_delivery' => (string)$shipment->EstimatedDelivery ?? null,
        ];
    }

    private function normalizeShipmentsResponse($result): array
    {
        $shipments = [];
        
        if (isset($result->Shipments) && isset($result->Shipments->Shipment)) {
            foreach ($result->Shipments->Shipment as $shipment) {
                $shipments[] = $this->normalizeShipmentResponse((object)['Shipment' => $shipment]);
            }
        }
        
        $pagination = isset($result->Pagination) ? [
            'total' => (int)$result->Pagination->TotalRecords,
            'page' => (int)$result->Pagination->Page,
            'per_page' => (int)$result->Pagination->PageSize,
        ] : [];
        
        return [
            'data' => $shipments,
            'pagination' => $pagination,
        ];
    }

    private function normalizeRatesResponse($result): array
    {
        $rates = [];
        
        if (isset($result->Rates) && isset($result->Rates->Rate)) {
            $items = is_array($result->Rates->Rate) 
                ? $result->Rates->Rate 
                : [$result->Rates->Rate];
            
            foreach ($items as $rate) {
                $rates[] = [
                    'carrier' => (string)$rate->Carrier,
                    'service' => (string)$rate->Service,
                    'cost' => (float)$rate->Cost,
                    'currency' => (string)$rate->Currency ?? 'USD',
                    'delivery_days' => (int)$rate->DeliveryDays ?? null,
                ];
            }
        }
        
        return $rates;
    }

    private function normalizeTrackingResponse($result): ?array
    {
        if (!isset($result->TrackingInfo)) {
            return null;
        }
        
        $tracking = $result->TrackingInfo;
        
        $events = [];
        if (isset($tracking->Events) && isset($tracking->Events->Event)) {
            $items = is_array($tracking->Events->Event) 
                ? $tracking->Events->Event 
                : [$tracking->Events->Event];
            
            foreach ($items as $event) {
                $events[] = [
                    'timestamp' => (string)$event->Timestamp,
                    'status' => (string)$event->Status,
                    'location' => (string)$event->Location ?? null,
                    'description' => (string)$event->Description ?? null,
                ];
            }
        }
        
        return [
            'tracking_number' => (string)$tracking->TrackingNumber,
            'status' => (string)$tracking->Status,
            'estimated_delivery' => (string)$tracking->EstimatedDelivery ?? null,
            'events' => $events,
        ];
    }

    private function handleSoapFault(SoapFault $e, string $operation, array $context = []): void
    {
        $this->logger->error('Shipping SOAP fault', [
            'operation' => $operation,
            'fault_code' => $e->faultcode,
            'fault_string' => $e->getMessage(),
            'context' => $context,
        ]);
    }
}
