<?php
declare(strict_types=1);

namespace App\Refund\Workflow;

use App\Domain\Entity\Refund;
use App\Domain\Entity\PaymentTransaction;
use App\Domain\Service\PaymentGatewayInterface;
use App\Domain\Service\InventoryServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use App\Domain\Repository\RefundRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class RefundProcessingWorkflow
{
    public function __construct(
        private RefundRepositoryInterface $refundRepository,
        private PaymentGatewayInterface $paymentGateway,
        private InventoryServiceInterface $inventoryService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function processRefund(string $refundId): void
    {
        $refund = $this->refundRepository->findById($refundId);
        if ($refund === null) {
            throw new \RuntimeException("Refund not found: {$refundId}");
        }

        $this->logger->info('Starting refund processing workflow', ['refund_id' => $refundId]);

        $this->validateRefund($refund);

        $this->processRefundPayment($refund);

        $this->restoreInventory($refund);

        $this->updateRefundStatus($refund, 'completed');

        $this->sendRefundConfirmation($refund);

        $this->recordAuditEvent($refund, 'refund_processed');

        $this->logger->info('Refund processing workflow completed', ['refund_id' => $refundId]);
    }

    private function validateRefund(Refund $refund): void
    {
        if ($refund->getStatus() !== 'pending') {
            throw new \RuntimeException("Refund {$refund->getId()} is not in pending status");
        }

        if ($refund->getAmount() <= 0) {
            throw new \RuntimeException("Refund {$refund->getId()} has invalid amount");
        }

        if ($refund->getOriginalTransactionId() === null) {
            throw new \RuntimeException("Refund {$refund->getId()} has no original transaction");
        }

        $this->logger->debug('Refund validation passed', ['refund_id' => $refund->getId()->toString()]);
    }

    private function processRefundPayment(Refund $refund): void
    {
        $transaction = $this->paymentGateway->refund(
            $refund->getOriginalTransactionId(),
            $refund->getAmount()
        );

        if (!$transaction->isSuccessful()) {
            $this->recordAuditEvent($refund, 'refund_payment_failed', [
                'reason' => $transaction->getFailureMessage(),
            ]);
            throw new \RuntimeException("Refund payment failed: {$transaction->getFailureMessage()}");
        }

        $refund->setRefundTransactionId($transaction->getId());
        $this->recordAuditEvent($refund, 'refund_payment_processed', ['transaction_id' => $transaction->getId()]);

        $this->logger->debug('Refund payment processed', [
            'refund_id' => $refund->getId()->toString(),
            'transaction_id' => $transaction->getId(),
        ]);
    }

    private function restoreInventory(Refund $refund): void
    {
        foreach ($refund->getItems() as $item) {
            $result = $this->inventoryService->restoreStock(
                $item->getProductId(),
                $item->getQuantity(),
                $refund->getId()->toString()
            );

            if (!$result->isSuccessful()) {
                $this->recordAuditEvent($refund, 'inventory_restore_failed', [
                    'product_id' => $item->getProductId()->toString(),
                    'reason' => $result->getMessage(),
                ]);
                throw new \RuntimeException("Inventory restore failed: {$result->getMessage()}");
            }

            $this->recordAuditEvent($refund, 'inventory_restored', [
                'product_id' => $item->getProductId()->toString(),
                'quantity' => $item->getQuantity(),
            ]);
        }

        $this->logger->debug('Inventory restored', ['refund_id' => $refund->getId()->toString()]);
    }

    private function sendRefundConfirmation(Refund $refund): void
    {
        $this->notificationService->send(
            $refund->getCustomerId(),
            'refund_completed',
            [
                'refund_id' => $refund->getId()->toString(),
                'amount' => $refund->getAmount(),
                'original_transaction_id' => $refund->getOriginalTransactionId(),
            ]
        );

        $this->recordAuditEvent($refund, 'refund_confirmation_sent');

        $this->logger->debug('Refund confirmation sent', ['refund_id' => $refund->getId()->toString()]);
    }

    private function updateRefundStatus(Refund $refund, string $status): void
    {
        $refund->setStatus($status);
        $refund->setProcessedAt(new \DateTimeImmutable());
        $this->refundRepository->save($refund);
    }

    private function recordAuditEvent(Refund $refund, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'refund_id' => $refund->getId()->toString(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
