<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\PdfGenerator;
use App\Service\EmailService;
use Psr\Log\LoggerInterface;

final class OrderBillingService
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly PdfGenerator $pdfGenerator,
        private readonly EmailService $emailService,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateMonthlyInvoices(int $year, int $month): array
    {
        $orders = $this->orderRepository->findCompletedForMonth($year, $month);
        $invoices = [];

        foreach ($orders as $order) {
            $invoice = $this->generateInvoice($order, $year, $month);

            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Monthly order invoices generated', [
            'year' => $year,
            'month' => $month,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function generateQuarterlyInvoices(int $year, int $quarter): array
    {
        $orders = $this->orderRepository->findCompletedForQuarter($year, $quarter);
        $invoices = [];

        foreach ($orders as $order) {
            $invoice = $this->generateQuarterlyInvoice($order, $year, $quarter);

            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Quarterly order invoices generated', [
            'year' => $year,
            'quarter' => $quarter,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function generateAnnualInvoices(int $year): array
    {
        $orders = $this->orderRepository->findCompletedForYear($year);
        $invoices = [];

        foreach ($orders as $order) {
            $invoice = $this->generateAnnualInvoice($order, $year);

            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Annual order invoices generated', [
            'year' => $year,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function sendInvoiceEmail(array $invoices): int
    {
        $sentCount = 0;

        foreach ($invoices as $invoice) {
            $customer = $invoice->getOrder()->getCustomer();

            $pdfPath = $this->pdfGenerator->generate($invoice);
            $emailResult = $this->emailService->send(
                $customer->getEmail(),
                $this->buildInvoiceEmailBody($invoice),
                'Invoice ' . $invoice->getNumber(),
                [$pdfPath]
            );

            if ($emailResult) {
                $sentCount++;
                $this->logger->info('Invoice email sent', [
                    'invoice_id' => $invoice->getId(),
                    'customer_email' => $customer->getEmail(),
                ]);
            } else {
                $this->logger->error('Failed to send invoice email', [
                    'invoice_id' => $invoice->getId(),
                    'customer_email' => $customer->getEmail(),
                ]);
            }
        }

        return $sentCount;
    }

    private function generateInvoice(Order $order, int $year, int $month): ?Invoice
    {
        $startDate = new \DateTime("{$year}-{$month}-01");
        $endDate = (clone $startDate)->modify('last day of this month');

        $amount = $order->calculateMonthlyAmount();
        $taxAmount = $amount * $order->getTaxRate();
        $totalAmount = $amount + $taxAmount;

        $invoice = new Invoice(
            $order,
            $startDate,
            $endDate,
            $amount,
            $taxAmount,
            $totalAmount
        );

        $order->addInvoice($invoice);

        return $invoice;
    }

    private function generateQuarterlyInvoice(Order $order, int $year, int $quarter): ?Invoice
    {
        $startMonth = (($quarter - 1) * 3) + 1;
        $startDate = new \DateTime("{$year}-{$startMonth}-01");
        $endDate = (clone $startDate)->modify('+2 months')->modify('last day of this month');

        $amount = $order->calculateQuarterlyAmount();
        $taxAmount = $amount * $order->getTaxRate();
        $totalAmount = $amount + $taxAmount;

        $invoice = new Invoice(
            $order,
            $startDate,
            $endDate,
            $amount,
            $taxAmount,
            $totalAmount
        );

        $order->addInvoice($invoice);

        return $invoice;
    }

    private function generateAnnualInvoice(Order $order, int $year): ?Invoice
    {
        $startDate = new \DateTime("{$year}-01-01");
        $endDate = new \DateTime("{$year}-12-31");

        $amount = $order->calculateAnnualAmount();
        $taxAmount = $amount * $order->getTaxRate();
        $totalAmount = $amount + $taxAmount;

        $invoice = new Invoice(
            $order,
            $startDate,
            $endDate,
            $amount,
            $taxAmount,
            $totalAmount
        );

        $order->addInvoice($invoice);

        return $invoice;
    }

    private function buildInvoiceEmailBody(Invoice $invoice): string
    {
        return sprintf(
            "Dear %s,\n\nPlease find attached your invoice %s for the period %s to %s.\n\nAmount: $%.2f\nTax: $%.2f\nTotal: $%.2f\n\nThank you for your business.",
            $invoice->getOrder()->getCustomer()->getName(),
            $invoice->getNumber(),
            $invoice->getStartDate()->format('Y-m-d'),
            $invoice->getEndDate()->format('Y-m-d'),
            $invoice->getAmount(),
            $invoice->getTaxAmount(),
            $invoice->getTotalAmount()
        );
    }
}
