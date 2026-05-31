<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\BillableInterface;
use App\Repository\BillableRepositoryInterface;
use App\Service\PdfGenerator;
use App\Service\EmailService;
use Psr\Log\LoggerInterface;

interface BillingServiceInterface
{
    public function generateMonthlyInvoices(int $year, int $month): array;
    public function generateQuarterlyInvoices(int $year, int $quarter): array;
    public function generateAnnualInvoices(int $year): array;
    public function sendInvoiceEmail(array $invoices): int;
}

abstract class AbstractBillingService implements BillingServiceInterface
{
    public function __construct(
        protected readonly BillableRepositoryInterface $repository,
        protected readonly PdfGenerator $pdfGenerator,
        protected readonly EmailService $emailService,
        protected readonly LoggerInterface $logger,
    ) {}

    public function generateMonthlyInvoices(int $year, int $month): array
    {
        $billables = $this->repository->findActiveForMonth($year, $month);
        $invoices = [];

        foreach ($billables as $billable) {
            $invoice = $this->generateMonthlyInvoice($billable, $year, $month);
            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Monthly invoices generated', [
            'type' => $this->getBillableType(),
            'year' => $year,
            'month' => $month,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function generateQuarterlyInvoices(int $year, int $quarter): array
    {
        $billables = $this->repository->findActiveForQuarter($year, $quarter);
        $invoices = [];

        foreach ($billables as $billable) {
            $invoice = $this->generateQuarterlyInvoice($billable, $year, $quarter);
            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Quarterly invoices generated', [
            'type' => $this->getBillableType(),
            'year' => $year,
            'quarter' => $quarter,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function generateAnnualInvoices(int $year): array
    {
        $billables = $this->repository->findActiveForYear($year);
        $invoices = [];

        foreach ($billables as $billable) {
            $invoice = $this->generateAnnualInvoice($billable, $year);
            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Annual invoices generated', [
            'type' => $this->getBillableType(),
            'year' => $year,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function sendInvoiceEmail(array $invoices): int
    {
        $sentCount = 0;

        foreach ($invoices as $invoice) {
            $customer = $this->getCustomerFromInvoice($invoice);

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

    protected function createInvoice(BillableInterface $billable, \DateTimeInterface $startDate, \DateTimeInterface $endDate, float $amount, float $taxRate): Invoice
    {
        $taxAmount = $amount * $taxRate;
        $totalAmount = $amount + $taxAmount;

        return new Invoice(
            $billable,
            $startDate,
            $endDate,
            $amount,
            $taxAmount,
            $totalAmount
        );
    }

    abstract protected function getBillableType(): string;
    abstract protected function getBillableTypeForMonth(int $year, int $month): array;
    abstract protected function getBillableTypeForQuarter(int $year, int $quarter): array;
    abstract protected function getBillableTypeForYear(int $year): array;
    abstract protected function getCustomerFromInvoice(Invoice $invoice): Customer;
    abstract protected function getTaxRate(BillableInterface $billable): float;
}
