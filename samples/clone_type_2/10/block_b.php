<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\UsageRecord;
use App\Repository\UsageRecordRepository;
use App\Service\PdfGenerator;
use App\Service\EmailService;
use Psr\Log\LoggerInterface;

final class UsageBillingService
{
    public function __construct(
        private readonly UsageRecordRepository $usageRecordRepository,
        private readonly PdfGenerator $pdfGenerator,
        private readonly EmailService $emailService,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateMonthlyInvoices(int $year, int $month): array
    {
        $usageRecords = $this->usageRecordRepository->findProcessedForMonth($year, $month);
        $invoices = [];

        foreach ($usageRecords as $usageRecord) {
            $invoice = $this->generateInvoice($usageRecord, $year, $month);

            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Monthly usage invoices generated', [
            'year' => $year,
            'month' => $month,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function generateQuarterlyInvoices(int $year, int $quarter): array
    {
        $usageRecords = $this->usageRecordRepository->findProcessedForQuarter($year, $quarter);
        $invoices = [];

        foreach ($usageRecords as $usageRecord) {
            $invoice = $this->generateQuarterlyInvoice($usageRecord, $year, $quarter);

            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Quarterly usage invoices generated', [
            'year' => $year,
            'quarter' => $quarter,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function generateAnnualInvoices(int $year): array
    {
        $usageRecords = $this->usageRecordRepository->findProcessedForYear($year);
        $invoices = [];

        foreach ($usageRecords as $usageRecord) {
            $invoice = $this->generateAnnualInvoice($usageRecord, $year);

            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Annual usage invoices generated', [
            'year' => $year,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function sendInvoiceEmail(array $invoices): int
    {
        $sentCount = 0;

        foreach ($invoices as $invoice) {
            $customer = $invoice->getUsageRecord()->getAccount()->getCustomer();

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

    private function generateInvoice(UsageRecord $usageRecord, int $year, int $month): ?Invoice
    {
        $startDate = new \DateTime("{$year}-{$month}-01");
        $endDate = (clone $startDate)->modify('last day of this month');

        $amount = $usageRecord->calculateMonthlyAmount();
        $taxAmount = $amount * $usageRecord->getAccount()->getTaxRate();
        $totalAmount = $amount + $taxAmount;

        $invoice = new Invoice(
            $usageRecord,
            $startDate,
            $endDate,
            $amount,
            $taxAmount,
            $totalAmount
        );

        $usageRecord->addInvoice($invoice);

        return $invoice;
    }

    private function generateQuarterlyInvoice(UsageRecord $usageRecord, int $year, int $quarter): ?Invoice
    {
        $startMonth = (($quarter - 1) * 3) + 1;
        $startDate = new \DateTime("{$year}-{$startMonth}-01");
        $endDate = (clone $startDate)->modify('+2 months')->modify('last day of this month');

        $amount = $usageRecord->calculateQuarterlyAmount();
        $taxAmount = $amount * $usageRecord->getAccount()->getTaxRate();
        $totalAmount = $amount + $taxAmount;

        $invoice = new Invoice(
            $usageRecord,
            $startDate,
            $endDate,
            $amount,
            $taxAmount,
            $totalAmount
        );

        $usageRecord->addInvoice($invoice);

        return $invoice;
    }

    private function generateAnnualInvoice(UsageRecord $usageRecord, int $year): ?Invoice
    {
        $startDate = new \DateTime("{$year}-01-01");
        $endDate = new \DateTime("{$year}-12-31");

        $amount = $usageRecord->calculateAnnualAmount();
        $taxAmount = $amount * $usageRecord->getAccount()->getTaxRate();
        $totalAmount = $amount + $taxAmount;

        $invoice = new Invoice(
            $usageRecord,
            $startDate,
            $endDate,
            $amount,
            $taxAmount,
            $totalAmount
        );

        $usageRecord->addInvoice($invoice);

        return $invoice;
    }

    private function buildInvoiceEmailBody(Invoice $invoice): string
    {
        return sprintf(
            "Dear %s,\n\nPlease find attached your invoice %s for the period %s to %s.\n\nAmount: $%.2f\nTax: $%.2f\nTotal: $%.2f\n\nThank you for your business.",
            $invoice->getUsageRecord()->getAccount()->getCustomer()->getName(),
            $invoice->getNumber(),
            $invoice->getStartDate()->format('Y-m-d'),
            $invoice->getEndDate()->format('Y-m-d'),
            $invoice->getAmount(),
            $invoice->getTaxAmount(),
            $invoice->getTotalAmount()
        );
    }
}
