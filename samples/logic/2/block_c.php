<?php

declare(strict_types=1);

namespace App\Logistics;

use App\Entity\Container;
use App\Repository\ContainerRepository;
use App\Service\WeightCalculator;
use Psr\Log\LoggerInterface;

final class ContainerLoadingService
{
    public function __construct(
        private readonly ContainerRepository $containerRepository,
        private readonly WeightCalculator $weightCalculator,
        private readonly LoggerInterface $logger,
    ) {}

    public function loadItems(int $containerId, array $items, string $reason): Container
    {
        $container = $this->containerRepository->findById($containerId);

        if ($container === null) {
            throw new \RuntimeException('Container not found');
        }

        if (empty($items)) {
            throw new \InvalidArgumentException('Items list cannot be empty');
        }

        if (!$this->isValidReason($reason)) {
            throw new \InvalidArgumentException('Invalid loading reason');
        }

        if ($container->getStatus() === 'sealed') {
            throw new \InvalidArgumentException('Cannot load items into sealed container');
        }

        if ($container->getStatus() === 'in_transit') {
            throw new \InvalidArgumentException('Cannot load items into container in transit');
        }

        if ($container->getStatus() === 'closed') {
            throw new \InvalidArgumentException('Cannot load items into closed container');
        }

        $totalWeight = $this->weightCalculator->calculateTotal($items);
        $maxWeight = $container->getMaxWeight();
        $currentWeight = $container->getCurrentWeight();
        $availableWeight = $maxWeight - $currentWeight;

        if ($totalWeight > $availableWeight) {
            throw new \InvalidArgumentException('Items exceed available weight capacity');
        }

        $container->addItems($items);
        $container->setCurrentWeight($currentWeight + $totalWeight);
        $container->setLastLoadingTime(new \DateTimeImmutable());

        $this->containerRepository->save($container);

        $this->logger->info('Items loaded successfully', [
            'container_id' => $containerId,
            'item_count' => count($items),
            'total_weight' => $totalWeight,
            'reason' => $reason,
        ]);

        return $container;
    }

    public function unloadItems(int $containerId, array $itemIds, string $reason): Container
    {
        $container = $this->containerRepository->findById($containerId);

        if ($container === null) {
            throw new \RuntimeException('Container not found');
        }

        if (empty($itemIds)) {
            throw new \InvalidArgumentException('Item IDs list cannot be empty');
        }

        if (!$this->isValidReason($reason)) {
            throw new \InvalidArgumentException('Invalid unloading reason');
        }

        if ($container->getStatus() === 'sealed') {
            throw new \InvalidArgumentException('Cannot unload items from sealed container');
        }

        if ($container->getStatus() === 'in_transit') {
            throw new \InvalidArgumentException('Cannot unload items from container in transit');
        }

        if ($container->getStatus() === 'closed') {
            throw new \InvalidArgumentException('Cannot unload items from closed container');
        }

        $itemsToRemove = $container->getItemsByIds($itemIds);
        $totalWeight = $this->weightCalculator->calculateTotal($itemsToRemove);

        $container->removeItems($itemIds);
        $container->setCurrentWeight($container->getCurrentWeight() - $totalWeight);
        $container->setLastUnloadingTime(new \DateTimeImmutable());

        $this->containerRepository->save($container);

        $this->logger->info('Items unloaded successfully', [
            'container_id' => $containerId,
            'item_count' => count($itemIds),
            'total_weight' => $totalWeight,
            'reason' => $reason,
        ]);

        return $container;
    }

    private function isValidReason(string $reason): bool
    {
        $validReasons = [
            'delivery',
            'returns',
            'cross_dock',
            'consolidation',
            'deconsolidation',
            'inspection',
        ];

        return in_array($reason, $validReasons, true);
    }
}
