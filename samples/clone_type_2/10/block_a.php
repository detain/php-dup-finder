<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Service\PdfGenerator;
use App\Service\EmailService;
use Psr\Log\LoggerInterface;

final class SubscriptionBillingService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly PdfGenerator $pdfGenerator,
        private readonly EmailService $emailService,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateMonthlyInvoices(int $year, int $month): array
    {
        $subscriptions = $this->subscriptionRepository->findActiveForMonth($year, $month);
        $invoices = [];

        foreach ($subscriptions as $subscription) {
            $invoice = $this->generateInvoice($subscription, $year, $month);

            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Monthly subscription invoices generated', [
            'year' => $year,
            'month' => $month,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function generateQuarterlyInvoices(int $year, int $quarter): array
    {
        $subscriptions = $this->subscriptionRepository->findActiveForQuarter($year, $quarter);
        $invoices = [];

        foreach ($subscriptions as $subscription) {
            $invoice = $this->generateQuarterlyInvoice($subscription, $year, $quarter);

            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Quarterly subscription invoices generated', [
            'year' => $year,
            'quarter' => $quarter,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function generateAnnualInvoices(int $year): array
    {
        $subscriptions = $this->subscriptionRepository->findActiveForYear($year);
        $invoices = [];

        foreach ($subscriptions as $subscription) {
            $invoice = $this->generateAnnualInvoice($subscription, $year);

            if ($invoice !== null) {
                $invoices[] = $invoice;
            }
        }

        $this->logger->info('Annual subscription invoices generated', [
            'year' => $year,
            'count' => count($invoices),
        ]);

        return $invoices;
    }

    public function sendInvoiceEmail(array $invoices): int
    {
        $sentCount = 0;

        foreach ($invoices as $invoice) {
            $customer = $invoice->getSubscription()->getCustomer();

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

    private function generateInvoice(Subscription $subscription, int $year, int $month): ?Invoice
    {
        $startDate = new \DateTime("{$year}-{$month}-01");
        $endDate = (clone $startDate)->modify('last day of this month');

        $amount = $subscription->calculateMonthlyAmount();
        $taxAmount = $amount * $subscription->getTaxRate();
        $totalAmount = $amount + $taxAmount;

        $invoice = new Invoice(
            $subscription,
            $startDate,
            $endDate,
            $amount,
            $taxAmount,
            $totalAmount
        );

        $subscription->addInvoice($invoice);

        return $invoice;
    }

    private function generateQuarterlyInvoice(Subscription $subscription, int $year, int $quarter): ?Invoice
    {
        $startMonth = (($quarter - 1) * 3) + 1;
        $startDate = new \DateTime("{$year}-{$startMonth}-01");
        $endDate = (clone $startDate)->modify('+2 months')->modify('last day of this month');

        $amount = $subscription->calculateQuarterlyAmount();
        $taxAmount = $amount * $subscription->getTaxRate();
        $totalAmount = $amount + $taxAmount;

        $invoice = new Invoice(
            $subscription,
            $startDate,
            $endDate,
            $amount,
            $taxAmount,
            $totalAmount
        );

        $subscription->addInvoice($invoice);

        return $invoice;
    }

    private function generateAnnualInvoice(Subscription $subscription, int $year): ?Invoice
    {
        $startDate = new \DateTime("{$year}-01-01");
        $endDate = new \DateTime("{$year}-12-31");

        $amount = $subscription->calculateAnnualAmount();
        $taxAmount = $amount * $subscription->getTaxRate();
        $totalAmount = $amount + $taxAmount;

        $invoice = new Invoice(
            $subscription,
            $startDate,
            $endDate,
            $amount,
            $taxAmount,
            $totalAmount
        );

        $subscription->addInvoice($invoice);

        return $invoice;
    }

    private function buildInvoiceEmailBody(Invoice $invoice): string
    {
        return sprintf(
            "Dear %s,\n\nPlease find attached your invoice %s for the period %s to %s.\n\nAmount: $%.2f\nTax: $%.2f\nTotal: $%.2f\n\nThank you for your business.",
            $invoice->getSubscription()->getCustomer()->getName(),
            $invoice->getNumber(),
            $invoice->getStartDate()->format('Y-m-d'),
            $invoice->getEndDate()->format('Y-m-d'),
            $invoice->getAmount(),
            $invoice->getTaxAmount(),
            $invoice->getTotalAmount()
        );
    }
}
