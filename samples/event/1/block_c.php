<?php
declare(strict_types=1);

namespace App\Domain\Billing\EventHandler;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\AccountingService;
use App\Service\NotificationService;
use App\Service\AnalyticsService;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class PaymentReceivedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly AccountingService $accountingService,
        private readonly NotificationService $notificationService,
        private readonly AnalyticsService $analyticsService,
        private readonly AuditService $auditService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Payment $payment): void
    {
        $this->logger->info('Processing payment received event', [
            'payment_id' => $payment->getId(),
            'invoice_id' => $payment->getInvoiceId(),
            'amount' => $payment->getAmount()->getAmount(),
            'currency' => $payment->getAmount()->getCurrency(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->updateInvoiceStatus($payment);
            $this->reconcileAccounts($payment);
            $this->notifyAccounting($payment);
            $this->triggerFulfillment($payment);
            $this->recordAnalyticsEvent($payment);
            $this->createAuditLogEntry($payment);

            $this->entityManager->commit();

            $this->logger->info('Payment received event processed successfully', [
                'payment_id' => $payment->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process payment received event', [
                'payment_id' => $payment->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function updateInvoiceStatus(Payment $payment): void
    {
        $invoice = $this->entityManager
            ->getRepository(Invoice::class)
            ->find($payment->getInvoiceId());

        if ($invoice === null) {
            throw new \RuntimeException(
                sprintf('Invoice %d not found for payment', $payment->getInvoiceId())
            );
        }

        $totalPaid = $invoice->getTotalPaid() + $payment->getAmount()->getAmount();
        $invoice->setTotalPaid($totalPaid);

        $isFullyPaid = $totalPaid >= $invoice->getTotalDue()->getAmount();
        $invoice->setStatus($isFullyPaid ? 'paid' : 'partial');
        $invoice->setPaidAt($isFullyPaid ? new \DateTimeImmutable() : null);

        if ($isFullyPaid) {
            $invoice->setBalance(0);
        } else {
            $balance = $invoice->getTotalDue()->getAmount() - $totalPaid;
            $invoice->setBalance($balance);
        }

        $invoice->addPayment($payment);
        $this->entityManager->persist($invoice);

        $this->logger->debug('Updated invoice status', [
            'invoice_id' => $invoice->getId(),
            'status' => $invoice->getStatus(),
            'total_paid' => $totalPaid,
            'balance' => $invoice->getBalance(),
        ]);
    }

    private function reconcileAccounts(Payment $payment): void
    {
        $transaction = new \App\Entity\LedgerTransaction();
        $transaction->setType('receipt');
        $transaction->setAmount($payment->getAmount()->getAmount());
        $transaction->setCurrency($payment->getAmount()->getCurrency());
        $transaction->setAccount($this->getCashAccount());
        $transaction->setReferenceType('payment');
        $transaction->setReferenceId($payment->getId());
        $transaction->setDescription(sprintf(
            'Payment received for invoice #%d',
            $payment->getInvoiceId()
        ));
        $transaction->setTransactionDate(new \DateTimeImmutable());
        $transaction->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($transaction);

        $this->accountingService->recordTransaction($transaction);

        $this->logger->debug('Reconciled accounts for payment', [
            'payment_id' => $payment->getId(),
            'transaction_id' => $transaction->getId(),
            'account' => $this->getCashAccount(),
        ]);
    }

    private function notifyAccounting(Payment $payment): void
    {
        $accountingUsers = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->findByRole('accounting');

        $invoice = $this->entityManager
            ->getRepository(Invoice::class)
            ->find($payment->getInvoiceId());

        $notification = new \App\Entity\InternalNotification();
        $notification->setType('payment_received');
        $notification->setTitle('Payment Received');
        $notification->setBody(sprintf(
            'Payment of %s %s received from %s for invoice #%d',
            $payment->getAmount()->getCurrency(),
            number_format($payment->getAmount()->getAmount() / 100, 2),
            $invoice?->getCustomerName() ?? 'Unknown',
            $payment->getInvoiceId()
        ));
        $notification->setPriority('high');
        $notification->setMetadata([
            'payment_id' => $payment->getId(),
            'invoice_id' => $payment->getInvoiceId(),
            'amount' => $payment->getAmount()->getAmount(),
        ]);
        $notification->setCreatedAt(new \DateTimeImmutable());

        foreach ($accountingUsers as $user) {
            $recipient = new \App\Entity\NotificationRecipient();
            $recipient->setNotification($notification);
            $recipient->setUser($user);
            $recipient->setChannel('in_app');
            $this->entityManager->persist($recipient);
        }

        $this->entityManager->persist($notification);

        $this->queueService->publish('accounting.notifications', [
            'type' => 'payment_received',
            'payment_id' => $payment->getId(),
            'amount' => $payment->getAmount()->getAmount(),
            'recipients' => array_map(fn($u) => $u->getId(), $accountingUsers),
        ]);

        $this->logger->debug('Notified accounting department', [
            'payment_id' => $payment->getId(),
            'recipient_count' => count($accountingUsers),
        ]);
    }

    private function triggerFulfillment(Payment $payment): void
    {
        $invoice = $this->entityManager
            ->getRepository(Invoice::class)
            ->find($payment->getInvoiceId());

        if ($invoice === null || $invoice->getStatus() !== 'paid') {
            return;
        }

        $fulfillmentRules = $this->entityManager
            ->getRepository(\App\Entity\FulfillmentRule::class)
            ->findBy(['isActive' => true]);

        foreach ($fulfillmentRules as $rule) {
            if ($this->shouldFulfill($rule, $invoice)) {
                $fulfillmentOrder = new \App\Entity\FulfillmentOrder();
                $fulfillmentOrder->setInvoice($invoice);
                $fulfillmentOrder->setRule($rule);
                $fulfillmentOrder->setStatus('pending');
                $fulfillmentOrder->setPriority($rule->getPriority());
                $fulfillmentOrder->setCreatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($fulfillmentOrder);

                $this->queueService->publish('fulfillment.orders', [
                    'fulfillment_id' => $fulfillmentOrder->getId(),
                    'invoice_id' => $invoice->getId(),
                    'rule_type' => $rule->getType(),
                    'priority' => $rule->getPriority(),
                ]);

                $this->logger->info('Created fulfillment order', [
                    'invoice_id' => $invoice->getId(),
                    'rule_type' => $rule->getType(),
                ]);
            }
        }
    }

    private function recordAnalyticsEvent(Payment $payment): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('payment_received');
        $analyticsEvent->setCustomerId($payment->getCustomerId());
        $analyticsEvent->setPayload([
            'payment_id' => $payment->getId(),
            'invoice_id' => $payment->getInvoiceId(),
            'amount' => $payment->getAmount()->getAmount(),
            'currency' => $payment->getAmount()->getCurrency(),
            'payment_method' => $payment->getMethod(),
            'gateway' => $payment->getGateway(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);
        $this->analyticsService->enqueueBatchFlush();

        $this->logger->debug('Recorded analytics event', [
            'payment_id' => $payment->getId(),
            'event' => 'payment_received',
        ]);
    }

    private function createAuditLogEntry(Payment $payment): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('PAYMENT_RECEIVED');
        $auditEntry->setEntityType('payment');
        $auditEntry->setEntityId($payment->getId());
        $auditEntry->setUserId($payment->getCustomerId());
        $auditEntry->setMetadata([
            'invoice_id' => $payment->getInvoiceId(),
            'amount' => $payment->getAmount()->getAmount(),
            'currency' => $payment->getAmount()->getCurrency(),
            'payment_method' => $payment->getMethod(),
            'gateway_reference' => $payment->getGatewayReference(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'payment_id' => $payment->getId(),
            'action' => 'PAYMENT_RECEIVED',
        ]);
    }

    private function getCashAccount(): string
    {
        return $this->entityManager
            ->getRepository(\App\Entity\SystemSetting::class)
            ->findOneBy(['key' => 'cash_account'])
            ?->getValue() ?? '1000';
    }

    private function shouldFulfill(\App\Entity\FulfillmentRule $rule, Invoice $invoice): bool
    {
        $minAmount = $rule->getMinInvoiceAmount();
        if ($minAmount !== null && $invoice->getTotalDue()->getAmount() < $minAmount) {
            return false;
        }

        $applicableTypes = $rule->getInvoiceTypes();
        if (!empty($applicableTypes) && !in_array($invoice->getType(), $applicableTypes, true)) {
            return false;
        }

        return true;
    }
}
