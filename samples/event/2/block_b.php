<?php
declare(strict_types=1);

namespace App\Domain\Inventory\EventHandler;

use App\Entity\Product;
use App\Entity\WaitlistEntry;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\SearchService;
use App\Service\SuggestionService;
use App\Service\MetricsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ItemOutOfStockEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly SearchService $searchService,
        private readonly SuggestionService $suggestionService,
        private readonly MetricsService $metricsService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Product $product): void
    {
        $this->logger->info('Processing item out of stock event', [
            'product_id' => $product->getId(),
            'sku' => $product->getSku(),
            'current_stock' => $product->getStockQuantity(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->notifyWaitlistCustomers($product);
            $this->updateSearchRanking($product);
            $this->suggestAlternatives($product);
            $this->recordStockMetric($product);
            $this->createAuditEntry($product);
            $this->triggerReplenishmentAlert($product);

            $this->entityManager->commit();

            $this->logger->info('Item out of stock event processed', [
                'product_id' => $product->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process item out of stock event', [
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function notifyWaitlistCustomers(Product $product): void
    {
        $waitlistEntries = $this->entityManager
            ->getRepository(WaitlistEntry::class)
            ->findPendingByProduct($product->getId());

        if (empty($waitlistEntries)) {
            $this->logger->debug('No waitlist entries for product', [
                'product_id' => $product->getId(),
            ]);
            return;
        }

        foreach ($waitlistEntries as $entry) {
            $notification = new \App\Entity\StockNotification();
            $notification->setCustomer($entry->getCustomer());
            $notification->setProduct($product);
            $notification->setType('back_in_stock');
            $notification->setStatus('pending');
            $notification->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($notification);

            $this->queueService->publish('notification.back_in_stock', [
                'notification_id' => $notification->getId(),
                'customer_id' => $entry->getCustomerId(),
                'product_id' => $product->getId(),
                'product_name' => $product->getName(),
                'customer_email' => $entry->getCustomer()->getEmail(),
                'priority' => 'high',
            ]);

            $entry->setNotified(true);
            $entry->setNotifiedAt(new \DateTimeImmutable());
            $this->entityManager->persist($entry);
        }

        $this->logger->info('Notified waitlist customers', [
            'product_id' => $product->getId(),
            'notification_count' => count($waitlistEntries),
        ]);
    }

    private function updateSearchRanking(Product $product): void
    {
        $product->setSearchRanking($product->getSearchRanking() - 50);
        $product->setLastStockUpdate(new \DateTimeImmutable());

        $this->entityManager->persist($product);

        $this->searchService->updateProductRanking($product->getId(), [
            'in_stock' => false,
            'stock_quantity' => 0,
            'ranking_score' => $product->getSearchRanking(),
        ]);

        $this->logger->debug('Updated search ranking for out of stock product', [
            'product_id' => $product->getId(),
            'new_ranking' => $product->getSearchRanking(),
        ]);
    }

    private function suggestAlternatives(Product $product): void
    {
        $alternatives = $this->suggestionService->findSimilarProducts(
            $product->getCategoryId(),
            $product->getPriceRange(),
            excludeProductId: $product->getId()
        );

        foreach ($alternatives as $alternative) {
            $mapping = new \App\Entity\ProductAlternative();
            $mapping->setOriginalProduct($product);
            $mapping->setAlternativeProduct($alternative);
            $mapping->setSimilarityScore($alternative->getSimilarityScore());
            $mapping->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($mapping);

            $this->queueService->publish('catalog.alternatives', [
                'original_product_id' => $product->getId(),
                'alternative_product_id' => $alternative->getId(),
                'similarity_score' => $alternative->getSimilarityScore(),
            ]);
        }

        $this->logger->debug('Suggested alternative products', [
            'product_id' => $product->getId(),
            'alternative_count' => count($alternatives),
        ]);
    }

    private function recordStockMetric(Product $product): void
    {
        $metric = new AnalyticsEvent();
        $metric->setEventName('item_out_of_stock');
        $metric->setCustomerId($product->getId());
        $metric->setPayload([
            'product_id' => $product->getId(),
            'sku' => $product->getSku(),
            'category_id' => $product->getCategoryId(),
            'last_stock_update' => $product->getLastStockUpdate()?->format(\DATE_ATOM),
            'supplier_id' => $product->getSupplierId(),
        ]);
        $metric->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($metric);

        $this->metricsService->recordGauge('inventory.out_of_stock.count', 1, [
            'product_id' => $product->getId(),
            'category' => $product->getCategory()?->getName() ?? 'unknown',
        ]);

        $this->logger->debug('Recorded stock metric', [
            'product_id' => $product->getId(),
            'event' => 'item_out_of_stock',
        ]);
    }

    private function createAuditEntry(Product $product): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('ITEM_OUT_OF_STOCK');
        $auditEntry->setEntityType('product');
        $auditEntry->setEntityId($product->getId());
        $auditEntry->setUserId(0);
        $auditEntry->setMetadata([
            'sku' => $product->getSku(),
            'product_name' => $product->getName(),
            'category' => $product->getCategory()?->getName(),
            'last_stock_quantity' => $product->getStockQuantity(),
            'last_stock_update' => $product->getLastStockUpdate()?->format(\DATE_ATOM),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'product_id' => $product->getId(),
            'action' => 'ITEM_OUT_OF_STOCK',
        ]);
    }

    private function triggerReplenishmentAlert(Product $product): void
    {
        $supplier = $this->entityManager
            ->getRepository(\App\Entity\Supplier::class)
            ->find($product->getSupplierId());

        if ($supplier === null) {
            return;
        }

        $reorderThreshold = $product->getReorderThreshold();
        $currentStock = $product->getStockQuantity();

        if ($currentStock <= $reorderThreshold) {
            $alert = new \App\Entity\ReplenishmentAlert();
            $alert->setProduct($product);
            $alert->setSupplier($supplier);
            $alert->setCurrentStock($currentStock);
            $alert->setReorderThreshold($reorderThreshold);
            $alert->setRecommendedOrderQty($product->getReorderQuantity());
            $alert->setStatus('pending');
            $alert->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($alert);

            $this->queueService->publish('inventory.replenishment', [
                'alert_id' => $alert->getId(),
                'product_id' => $product->getId(),
                'supplier_id' => $supplier->getId(),
                'supplier_email' => $supplier->getEmail(),
                'current_stock' => $currentStock,
                'recommended_qty' => $product->getReorderQuantity(),
                'urgency' => $currentStock <= 0 ? 'critical' : 'normal',
            ]);

            $this->logger->info('Triggered replenishment alert', [
                'product_id' => $product->getId(),
                'supplier_id' => $supplier->getId(),
            ]);
        }
    }
}
