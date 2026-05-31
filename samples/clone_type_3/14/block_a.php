<?php

declare(strict_types=1);

namespace App\Processor;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\PdfGenerator;
use App\Service\NotificationService;
use Psr\Log\LoggerInterface;

final class OrderBatchProcessor
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly PdfGenerator $pdfGenerator,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {}

    public function processShipments(array $orderIds): ProcessingResult
    {
        $processed = 0;
        $failed = [];
        $orders = $this->orderRepository->findByIds($orderIds);

        foreach ($orders as $order) {
            try {
                $this->validateOrderForShipment($order);

                $order->markAsShipped();
                $order->setShippedAt(new \DateTime());

                $this->orderRepository->save($order);

                $this->notificationService->sendShippingNotification($order);

                $this->logger->info('Order marked as shipped', [
                    'order_id' => $order->getId(),
                ]);

                $processed++;
            } catch (\Exception $e) {
                $failed[] = [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ];

                $this->logger->error('Failed to process shipment', [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ProcessingResult($processed, count($failed), $failed);
    }

    public function processRefunds(array $orderIds, array $refundData): ProcessingResult
    {
        $processed = 0;
        $failed = [];
        $orders = $this->orderRepository->findByIds($orderIds);

        foreach ($orders as $order) {
            try {
                $this->validateOrderForRefund($order);

                $refundAmount = $refundData[$order->getId()] ?? $order->getTotal();

                $order->markAsRefunded($refundAmount);
                $order->setRefundedAt(new \DateTime());

                $this->orderRepository->save($order);

                $this->notificationService->sendRefundNotification($order, $refundAmount);

                $this->logger->info('Order marked as refunded', [
                    'order_id' => $order->getId(),
                    'amount' => $refundAmount,
                ]);

                $processed++;
            } catch (\Exception $e) {
                $failed[] = [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ];

                $this->logger->error('Failed to process refund', [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ProcessingResult($processed, count($failed), $failed);
    }

    public function processInvoices(array $orderIds): ProcessingResult
    {
        $processed = 0;
        $failed = [];
        $orders = $this->orderRepository->findByIds($orderIds);

        foreach ($orders as $order) {
            try {
                $this->validateOrderForInvoice($order);

                $pdfPath = $this->pdfGenerator->generateInvoice($order);

                $order->setInvoicePath($pdfPath);
                $order->markAsInvoiced();

                $this->orderRepository->save($order);

                $this->notificationService->sendInvoiceNotification($order, $pdfPath);

                $this->logger->info('Invoice generated for order', [
                    'order_id' => $order->getId(),
                    'pdf_path' => $pdfPath,
                ]);

                $processed++;
            } catch (\Exception $e) {
                $failed[] = [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ];

                $this->logger->error('Failed to generate invoice', [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ProcessingResult($processed, count($failed), $failed);
    }

    private function validateOrderForShipment(Order $order): void
    {
        if (!$order->canBeShipped()) {
            throw new \RuntimeException('Order cannot be shipped in current status: ' . $order->getStatus());
        }
    }

    private function validateOrderForRefund(Order $order): void
    {
        if (!$order->canBeRefunded()) {
            throw new \RuntimeException('Order cannot be refunded in current status: ' . $order->getStatus());
        }
    }

    private function validateOrderForInvoice(Order $order): void
    {
        if (!$order->canGenerateInvoice()) {
            throw new \RuntimeException('Order cannot generate invoice in current status: ' . $order->getStatus());
        }
    }
}
