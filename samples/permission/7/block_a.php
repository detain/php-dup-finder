<?php
declare(strict_types=1);

namespace App\Shipping\Authorization;

use App\Domain\Entity\User;
use App\Domain\Entity\Shipment;
use App\Domain\Repository\ShipmentRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class ShippingPermissionService
{
    public function __construct(
        private ShipmentRepositoryInterface $shipmentRepository,
        private LoggerInterface $logger,
    ) {}

    public function canGetRateQuote(User $user, string $originZip, string $destZip): bool
    {
        if ($user === null) {
            $this->logger->warning('Rate quote permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Rate quote permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('shipping', 'quote')) {
            $this->logger->info('Rate quote permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if ($this->isRestrictedRoute($originZip, $destZip) && !$user->hasPermission('shipping', 'quote_restricted')) {
            $this->logger->info('Rate quote permission denied: restricted route', [
                'user_id' => $user->getId()->toString(),
                'origin' => $originZip,
                'destination' => $destZip,
            ]);
            return false;
        }

        $this->logger->debug('Rate quote permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canCreateShipment(User $user, string $orderId): bool
    {
        if ($user === null) {
            $this->logger->warning('Shipment create permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Shipment create permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'order_id' => $orderId,
            ]);
            return false;
        }

        if (!$user->hasPermission('shipping', 'create')) {
            $this->logger->info('Shipment create permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'order_id' => $orderId,
            ]);
            return false;
        }

        $this->logger->debug('Shipment create permission granted', [
            'user_id' => $user->getId()->toString(),
            'order_id' => $orderId,
        ]);

        return true;
    }

    public function canCancelShipment(User $user, string $shipmentId): bool
    {
        if ($user === null) {
            $this->logger->warning('Shipment cancel permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Shipment cancel permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'shipment_id' => $shipmentId,
            ]);
            return false;
        }

        $shipment = $this->shipmentRepository->findById($shipmentId);
        if ($shipment === null) {
            $this->logger->info('Shipment cancel permission denied: shipment not found', [
                'shipment_id' => $shipmentId,
            ]);
            return false;
        }

        if (!$shipment->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('shipping', 'cancel_others')) {
                $this->logger->info('Shipment cancel permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'shipment_id' => $shipmentId,
                ]);
                return false;
            }
        }

        if (!$shipment->isCancellable()) {
            $this->logger->info('Shipment cancel permission denied: not cancellable', [
                'shipment_id' => $shipmentId,
            ]);
            return false;
        }

        $this->logger->debug('Shipment cancel permission granted', [
            'user_id' => $user->getId()->toString(),
            'shipment_id' => $shipmentId,
        ]);

        return true;
    }

    public function canModifyShipment(User $user, string $shipmentId): bool
    {
        if ($user === null) {
            $this->logger->warning('Shipment modify permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Shipment modify permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'shipment_id' => $shipmentId,
            ]);
            return false;
        }

        $shipment = $this->shipmentRepository->findById($shipmentId);
        if ($shipment === null) {
            $this->logger->info('Shipment modify permission denied: shipment not found', [
                'shipment_id' => $shipmentId,
            ]);
            return false;
        }

        if (!$shipment->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('shipping', 'modify_others')) {
                $this->logger->info('Shipment modify permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'shipment_id' => $shipmentId,
                ]);
                return false;
            }
        }

        if (!$shipment->isModifiable()) {
            $this->logger->info('Shipment modify permission denied: not modifiable', [
                'shipment_id' => $shipmentId,
            ]);
            return false;
        }

        $this->logger->debug('Shipment modify permission granted', [
            'user_id' => $user->getId()->toString(),
            'shipment_id' => $shipmentId,
        ]);

        return true;
    }

    public function canViewTracking(User $user, string $shipmentId): bool
    {
        if ($user === null) {
            $this->logger->warning('Tracking view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Tracking view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'shipment_id' => $shipmentId,
            ]);
            return false;
        }

        $shipment = $this->shipmentRepository->findById($shipmentId);
        if ($shipment === null) {
            $this->logger->info('Tracking view permission denied: shipment not found', [
                'shipment_id' => $shipmentId,
            ]);
            return false;
        }

        if (!$shipment->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('shipping', 'view_others')) {
                $this->logger->info('Tracking view permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'shipment_id' => $shipmentId,
                ]);
                return false;
            }
        }

        $this->logger->debug('Tracking view permission granted', [
            'user_id' => $user->getId()->toString(),
            'shipment_id' => $shipmentId,
        ]);

        return true;
    }

    private function isRestrictedRoute(string $originZip, string $destZip): bool
    {
        return false;
    }
}
