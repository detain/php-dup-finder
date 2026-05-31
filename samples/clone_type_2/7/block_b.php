<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Shipment;
use App\Repository\ShipmentRepository;
use App\Service\EmailService;
use App\Service\SmsService;
use Psr\Log\LoggerInterface;

final class ShipmentNotificationService
{
    public function __construct(
        private readonly ShipmentRepository $shipmentRepository,
        private readonly EmailService $emailService,
        private readonly SmsService $smsService,
        private readonly LoggerInterface $logger,
    ) {}

    public function notifyShipmentCreated(Shipment $shipment): void
    {
        $recipient = $shipment->getRecipient();
        $template = 'shipment_created';

        $emailResult = $this->emailService->send(
            $recipient->getEmail(),
            $this->renderEmailTemplate($template, $shipment),
            'Shipment Notification - #' . $shipment->getTrackingNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send shipment created email', [
                'shipment_id' => $shipment->getId(),
                'recipient_email' => $recipient->getEmail(),
            ]);
        }

        if ($recipient->getPhone()) {
            $smsResult = $this->smsService->send(
                $recipient->getPhone(),
                $this->renderSmsTemplate($template, $shipment)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send shipment created SMS', [
                    'shipment_id' => $shipment->getId(),
                    'recipient_phone' => $recipient->getPhone(),
                ]);
            }
        }

        $this->logger->info('Shipment created notifications sent', [
            'shipment_id' => $shipment->getId(),
            'recipient_id' => $recipient->getId(),
        ]);
    }

    public function notifyShipmentInTransit(Shipment $shipment): void
    {
        $recipient = $shipment->getRecipient();
        $template = 'shipment_in_transit';

        $emailResult = $this->emailService->send(
            $recipient->getEmail(),
            $this->renderEmailTemplate($template, $shipment),
            'Shipment Update - #' . $shipment->getTrackingNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send shipment in transit email', [
                'shipment_id' => $shipment->getId(),
                'recipient_email' => $recipient->getEmail(),
            ]);
        }

        if ($recipient->getPhone()) {
            $smsResult = $this->smsService->send(
                $recipient->getPhone(),
                $this->renderSmsTemplate($template, $shipment)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send shipment in transit SMS', [
                    'shipment_id' => $shipment->getId(),
                    'recipient_phone' => $recipient->getPhone(),
                ]);
            }
        }

        $this->logger->info('Shipment in transit notifications sent', [
            'shipment_id' => $shipment->getId(),
            'recipient_id' => $recipient->getId(),
        ]);
    }

    public function notifyShipmentOutForDelivery(Shipment $shipment): void
    {
        $recipient = $shipment->getRecipient();
        $template = 'shipment_out_for_delivery';

        $emailResult = $this->emailService->send(
            $recipient->getEmail(),
            $this->renderEmailTemplate($template, $shipment),
            'Out for Delivery - #' . $shipment->getTrackingNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send shipment out for delivery email', [
                'shipment_id' => $shipment->getId(),
                'recipient_email' => $recipient->getEmail(),
            ]);
        }

        if ($recipient->getPhone()) {
            $smsResult = $this->smsService->send(
                $recipient->getPhone(),
                $this->renderSmsTemplate($template, $shipment)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send shipment out for delivery SMS', [
                    'shipment_id' => $shipment->getId(),
                    'recipient_phone' => $recipient->getPhone(),
                ]);
            }
        }

        $this->logger->info('Shipment out for delivery notifications sent', [
            'shipment_id' => $shipment->getId(),
            'recipient_id' => $recipient->getId(),
        ]);
    }

    public function notifyShipmentDelivered(Shipment $shipment): void
    {
        $recipient = $shipment->getRecipient();
        $template = 'shipment_delivered';

        $emailResult = $this->emailService->send(
            $recipient->getEmail(),
            $this->renderEmailTemplate($template, $shipment),
            'Delivered - #' . $shipment->getTrackingNumber()
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send shipment delivered email', [
                'shipment_id' => $shipment->getId(),
                'recipient_email' => $recipient->getEmail(),
            ]);
        }

        if ($recipient->getPhone()) {
            $smsResult = $this->smsService->send(
                $recipient->getPhone(),
                $this->renderSmsTemplate($template, $shipment)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send shipment delivered SMS', [
                    'shipment_id' => $shipment->getId(),
                    'recipient_phone' => $recipient->getPhone(),
                ]);
            }
        }

        $this->logger->info('Shipment delivered notifications sent', [
            'shipment_id' => $shipment->getId(),
            'recipient_id' => $recipient->getId(),
        ]);
    }

    private function renderEmailTemplate(string $template, Shipment $shipment): string
    {
        return str_replace(
            ['{{tracking_number}}', '{{recipient_name}}', '{{carrier}}', '{{estimated_date}}'],
            [$shipment->getTrackingNumber(), $shipment->getRecipient()->getName(), $shipment->getCarrier(), $shipment->getEstimatedDeliveryDate()->format('Y-m-d')],
            file_get_contents(__DIR__ . '/templates/' . $template . '.html')
        );
    }

    private function renderSmsTemplate(string $template, Shipment $shipment): string
    {
        return sprintf(
            'Shipment %s is %s. Track: %s',
            $shipment->getTrackingNumber(),
            str_replace('_', ' ', $template),
            $shipment->getCarrier()
        );
    }
}
