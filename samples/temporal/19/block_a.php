<?php
declare(strict_types=1);

namespace FedEx\Ship\Service;

use FedEx\Ship\Repository\ShipmentRepository;
use FedEx\Ship\Repository\PackageRepository;
use FedEx\Ship\Repository\LabelRepository;
use FedEx\Ship\Entity\Shipment;
use FedEx\Ship\Entity\Package;
use FedEx\Ship\Entity\ShippingLabel;
use FedEx\Ship\Entity\Rate\ShopResult;
use FedEx\Ship\Exception\ShipmentException;
use FedEx\Ship\Service\Rating\RateService;
use FedEx\Ship\Service\Validation\AddressValidator;
use Psr\Log\LoggerInterface;

final class ShipmentCreationService
{
    private ShipmentRepository $shipmentRepo;
    private PackageRepository $packageRepo;
    private LabelRepository $labelRepo;
    private RateService $rateService;
    private AddressValidator $addressValidator;
    private LoggerInterface $logger;

    public function __construct(
        ShipmentRepository $shipmentRepo,
        PackageRepository $packageRepo,
        LabelRepository $labelRepo,
        RateService $rateService,
        AddressValidator $addressValidator,
        LoggerInterface $logger
    ) {
        $this->shipmentRepo = $shipmentRepo;
        $this->packageRepo = $packageRepo;
        $this->labelRepo = $labelRepo;
        $this->rateService = $rateService;
        $this->addressValidator = $addressValidator;
        $this->logger = $logger;
    }

    public function createShipment(array $shipmentData): ShipmentResult
    {
        $this->logger->info('Creating shipment', [
            'service_type' => $shipmentData['service_type'] ?? 'unknown'
        ]);

        $originAddress = $shipmentData['origin'];
        $destinationAddress = $shipmentData['destination'];

        $originValidation = $this->addressValidator->validate($originAddress);
        if (!$originValidation->isValid()) {
            throw new ShipmentException('Invalid origin address: ' . $originValidation->getError());
        }

        $destValidation = $this->addressValidator->validate($destinationAddress);
        if (!$destValidation->isValid()) {
            throw new ShipmentException('Invalid destination address: ' . $destValidation->getError());
        }

        $shipmentLock = $this->shipmentRepo->acquireCreationLock(
            $originAddress['postal_code'],
            $destinationAddress['postal_code']
        );

        if ($shipmentLock === null) {
            throw new ShipmentException('Could not acquire shipment creation lock');
        }

        $this->logger->debug('Shipment creation lock acquired');

        try {
            $shipment = Shipment::create([
                'origin_address' => json_encode($originAddress),
                'destination_address' => json_encode($destinationAddress),
                'service_type' => $shipmentData['service_type'],
                'status' => 'creating',
                'ship_date' => $shipmentData['ship_date'] ?? new \DateTimeImmutable(),
                'created_at' => new \DateTimeImmutable()
            ]);

            $savedShipment = $this->shipmentRepo->save($shipment);
            $this->logger->debug('Shipment record created', ['shipment_id' => $savedShipment->getId()]);

            $packages = [];
            foreach ($shipmentData['packages'] as $packageData) {
                $package = Package::create([
                    'shipment_id' => $savedShipment->getId(),
                    'weight' => $packageData['weight'],
                    'weight_unit' => $packageData['weight_unit'] ?? 'LB',
                    'dimensions' => json_encode([
                        'length' => $packageData['dimensions']['length'],
                        'width' => $packageData['dimensions']['width'],
                        'height' => $packageData['dimensions']['height'],
                        'unit' => $packageData['dimensions']['unit'] ?? 'IN'
                    ]),
                    'declared_value' => $packageData['declared_value'] ?? 0,
                    'status' => 'pending'
                ]);

                $savedPackage = $this->packageRepo->save($package);
                $packages[] = $savedPackage;
            }

            $this->logger->debug('Packages created', ['count' => count($packages)]);

            $rates = $this->rateService->getRates($savedShipment->getId());
            $selectedRate = $this->selectRate($rates, $shipmentData['preferred_rate'] ?? null);

            $this->shipmentRepo->updateRate($savedShipment->getId(), $selectedRate->getId());
            $this->shipmentRepo->updateStatus($savedShipment->getId(), 'rate_selected');

            $totalWeight = array_reduce($packages, fn($sum, $p) => $sum + $p->getWeight(), 0);
            $totalValue = array_reduce($packages, fn($sum, $p) => $sum + $p->getDeclaredValue(), 0);

            $this->shipmentRepo->updateTotals($savedShipment->getId(), $totalWeight, $totalValue);

            $labels = $this->generateLabels($savedShipment, $packages, $selectedRate);

            $this->shipmentRepo->updateStatus($savedShipment->getId(), 'labels_generated');

            $this->shipmentRepo->releaseCreationLock($shipmentLock);

            $this->logger->info('Shipment created successfully', [
                'shipment_id' => $savedShipment->getId(),
                'packages_count' => count($packages),
                'total_weight' => $totalWeight,
                'labels_count' => count($labels)
            ]);

            return new ShipmentResult([
                'success' => true,
                'shipment_id' => $savedShipment->getId(),
                'tracking_number' => $savedShipment->getTrackingNumber(),
                'labels' => array_map(fn($l) => $l->getUrl(), $labels),
                'total_weight' => $totalWeight,
                'estimated_cost' => $selectedRate->getCost()
            ]);

        } catch (\Throwable $e) {
            $this->shipmentRepo->releaseCreationLock($shipmentLock);
            $this->logger->error('Shipment creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function voidShipment(string $shipmentId, string $reason): VoidResult
    {
        $shipment = $this->shipmentRepo->findById($shipmentId);
        if ($shipment === null) {
            throw new ShipmentException("Shipment not found: {$shipmentId}");
        }

        if ($shipment->getStatus() === 'delivered') {
            throw new ShipmentException('Cannot void a delivered shipment');
        }

        $voidLock = $this->shipmentRepo->acquireVoidLock($shipmentId);
        if ($voidLock === null) {
            throw new ShipmentException('Could not acquire void lock');
        }

        try {
            $labels = $this->labelRepo->findByShipmentId($shipmentId);
            foreach ($labels as $label) {
                $this->labelRepo->voidLabel($label->getId(), $reason);
            }

            $this->shipmentRepo->updateStatus($shipmentId, 'voided', [
                'voided_at' => new \DateTimeImmutable(),
                'void_reason' => $reason
            ]);

            $this->shipmentRepo->releaseVoidLock($voidLock);

            $this->logger->info('Shipment voided', [
                'shipment_id' => $shipmentId,
                'reason' => $reason
            ]);

            return new VoidResult([
                'success' => true,
                'shipment_id' => $shipmentId,
                'voided_at' => (new \DateTimeImmutable())->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->shipmentRepo->releaseVoidLock($voidLock);
            $this->logger->error('Shipment void failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function generateLabels(Shipment $shipment, array $packages, Rate $rate): array
    {
        $labels = [];

        foreach ($packages as $package) {
            $labelData = $this->rateService->generateLabel(
                $shipment->getId(),
                $package->getId(),
                $rate->getCarrier()
            );

            $label = ShippingLabel::create([
                'shipment_id' => $shipment->getId(),
                'package_id' => $package->getId(),
                'carrier' => $rate->getCarrier(),
                'tracking_number' => $labelData['tracking_number'],
                'label_url' => $labelData['label_url'],
                'label_type' => $labelData['format'],
                'status' => 'active',
                'created_at' => new \DateTimeImmutable()
            ]);

            $savedLabel = $this->labelRepo->save($label);
            $labels[] = $savedLabel;
        }

        return $labels;
    }

    private function selectRate(array $rates, ?string $preferredRateId): Rate
    {
        if ($preferredRateId !== null) {
            foreach ($rates as $rate) {
                if ($rate->getId() === $preferredRateId) {
                    return $rate;
                }
            }
        }

        return current($rates);
    }
}
