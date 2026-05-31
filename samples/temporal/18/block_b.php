<?php
declare(strict_types=1);

namespace Zuora\Billing\Service;

use Zuora\Billing\Repository\SubscriptionRepository;
use Zuora\Billing\Repository\InvoiceRepository;
use Zuora\Billing\Repository\PaymentRepository;
use Zuora\Billing\Entity\Subscription;
use Zuora\Billing\Entity\Invoice;
use Zuora\Billing\Entity\InvoiceItem;
use Zuora\Billing\Entity\Payment;
use Zuora\Billing\Exception\BillingException;
use Zuora\Billing\Service\RatingEngine;
use Zuora\Billing\Service\DunningService;
use Psr\Log\LoggerInterface;

final class SubscriptionBillingService
{
    private SubscriptionRepository $subscriptionRepo;
    private InvoiceRepository $invoiceRepo;
    private PaymentRepository $paymentRepo;
    private RatingEngine $ratingEngine;
    private DunningService $dunningService;
    private LoggerInterface $logger;

    public function __construct(
        SubscriptionRepository $subscriptionRepo,
        InvoiceRepository $invoiceRepo,
        PaymentRepository $paymentRepo,
        RatingEngine $ratingEngine,
        DunningService $dunningService,
        LoggerInterface $logger
    ) {
        $this->subscriptionRepo = $subscriptionRepo;
        $this->invoiceRepo = $invoiceRepo;
        $this->paymentRepo = $paymentRepo;
        $this->ratingEngine = $ratingEngine;
        $this->dunningService = $dunningService;
        $this->logger = $logger;
    }

    public function billSubscription(string $subscriptionId, \DateTimeImmutable $targetDate): BillingResult
    {
        $this->logger->info('Starting subscription billing', [
            'subscription_id' => $subscriptionId,
            'target_date' => $targetDate->format('Y-m-d')
        ]);

        $subscription = $this->subscriptionRepo->findById($subscriptionId);
        if ($subscription === null) {
            throw new BillingException("Subscription not found: {$subscriptionId}");
        }

        if (!$subscription->isActive()) {
            throw new BillingException("Cannot bill inactive subscription");
        }

        $billingPeriod = $subscription->getCurrentPeriod($targetDate);

        $invoiceLock = $this->invoiceRepo->acquireBillingLock($subscriptionId);
        if ($invoiceLock === null) {
            throw new BillingException("Could not acquire billing lock for subscription: {$subscriptionId}");
        }

        $this->logger->debug('Billing lock acquired', ['subscription_id' => $subscriptionId]);

        try {
            $ratedItems = $this->ratingEngine->rateSubscription($subscription, $billingPeriod);

            $invoice = Invoice::create([
                'subscription_id' => $subscriptionId,
                'account_id' => $subscription->getAccountId(),
                'status' => 'draft',
                'billing_period_start' => $billingPeriod->getStart(),
                'billing_period_end' => $billingPeriod->getEnd(),
                'due_date' => $targetDate->modify('+30 days'),
                'created_at' => new \DateTimeImmutable()
            ]);

            $savedInvoice = $this->invoiceRepo->save($invoice);

            $totalAmount = 0;
            foreach ($ratedItems as $ratedItem) {
                $invoiceItem = InvoiceItem::create([
                    'invoice_id' => $savedInvoice->getId(),
                    'subscription_id' => $subscriptionId,
                    'product_id' => $ratedItem['product_id'],
                    'charge_type' => $ratedItem['charge_type'],
                    'description' => $ratedItem['description'],
                    'quantity' => $ratedItem['quantity'],
                    'unit_price' => $ratedItem['unit_price'],
                    'amount' => $ratedItem['amount'],
                    'created_at' => new \DateTimeImmutable()
                ]);

                $this->invoiceRepo->saveItem($invoiceItem);
                $totalAmount += $ratedItem['amount'];
            }

            $this->invoiceRepo->updateTotal($savedInvoice->getId(), $totalAmount);
            $this->invoiceRepo->finalize($savedInvoice->getId());

            $this->logger->info('Invoice created for subscription', [
                'subscription_id' => $subscriptionId,
                'invoice_id' => $savedInvoice->getId(),
                'total_amount' => $totalAmount
            ]);

            $paymentResult = $this->attemptAutoPayment($savedInvoice);

            if ($paymentResult['success']) {
                $this->invoiceRepo->updateStatus($savedInvoice->getId(), 'paid');
                $this->invoiceRepo->recordPayment($savedInvoice->getId(), $paymentResult['payment_id']);
            } else {
                $this->dunningService->scheduleDunning($savedInvoice);
            }

            $this->subscriptionRepo->updateLastBilledDate($subscriptionId, $targetDate);
            $this->invoiceRepo->releaseBillingLock($invoiceLock);

            $this->logger->info('Subscription billing completed', [
                'subscription_id' => $subscriptionId,
                'invoice_id' => $savedInvoice->getId(),
                'auto_paid' => $paymentResult['success']
            ]);

            return new BillingResult([
                'success' => true,
                'subscription_id' => $subscriptionId,
                'invoice_id' => $savedInvoice->getId(),
                'total_amount' => $totalAmount,
                'auto_paid' => $paymentResult['success']
            ]);

        } catch (\Throwable $e) {
            $this->invoiceRepo->releaseBillingLock($invoiceLock);
            $this->logger->error('Subscription billing failed', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function cancelSubscription(string $subscriptionId, string $cancelReason, bool $immediate = false): CancellationResult
    {
        $subscription = $this->subscriptionRepo->findById($subscriptionId);
        if ($subscription === null) {
            throw new BillingException("Subscription not found: {$subscriptionId}");
        }

        if (!$subscription->isActive()) {
            throw new BillingException("Subscription is not active");
        }

        $cancelDate = $immediate ? new \DateTimeImmutable() : $subscription->getCurrentPeriodEnd();

        $cancelLock = $this->subscriptionRepo->acquireCancellationLock($subscriptionId);
        if ($cancelLock === null) {
            throw new BillingException("Could not acquire cancellation lock");
        }

        try {
            if (!$immediate) {
                $this->subscriptionRepo->markForCancellation($subscriptionId, $cancelDate, $cancelReason);
            } else {
                $this->processImmediateCancellation($subscription, $cancelReason);
            }

            $this->subscriptionRepo->releaseCancellationLock($cancelLock);

            $this->logger->info('Subscription cancellation processed', [
                'subscription_id' => $subscriptionId,
                'cancel_date' => $cancelDate->format('c'),
                'immediate' => $immediate
            ]);

            return new CancellationResult([
                'success' => true,
                'subscription_id' => $subscriptionId,
                'cancelled_at' => (new \DateTimeImmutable())->format('c'),
                'effective_date' => $cancelDate->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->subscriptionRepo->releaseCancellationLock($cancelLock);
            $this->logger->error('Subscription cancellation failed', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function attemptAutoPayment(Invoice $invoice): array
    {
        $account = $this->paymentRepo->findAccountPaymentMethod($invoice->getAccountId());

        if ($account === null || !$account->hasAutoPayEnabled()) {
            return ['success' => false, 'reason' => 'no_auto_pay'];
        }

        try {
            $payment = Payment::create([
                'invoice_id' => $invoice->getId(),
                'account_id' => $invoice->getAccountId(),
                'amount' => $invoice->getTotal(),
                'method_id' => $account->getDefaultPaymentMethodId(),
                'status' => 'processed',
                'processed_at' => new \DateTimeImmutable()
            ]);

            $savedPayment = $this->paymentRepo->process($payment);

            return ['success' => true, 'payment_id' => $savedPayment->getId()];
        } catch (\Throwable $e) {
            return ['success' => false, 'reason' => $e->getMessage()];
        }
    }

    private function processImmediateCancellation(Subscription $subscription, string $reason): void
    {
        $proratedAmount = $this->ratingEngine->calculateProratedRefund(
            $subscription,
            new \DateTimeImmutable()
        );

        if ($proratedAmount > 0) {
            $this->paymentRepo->processRefund($subscription->getAccountId(), $proratedAmount);
        }

        $this->subscriptionRepo->cancel($subscription->getId(), $reason, new \DateTimeImmutable());
    }
}
