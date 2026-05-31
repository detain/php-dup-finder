<?php
declare(strict_types=1);

namespace App\Core\Payment\Processing;

use App\Domain\Entity\Payment;
use Psr\Log\LoggerInterface;

enum PaymentMethod
{
    case CreditCard;
    case Ach;
    case Wire;
}

interface PaymentWorkflowStepInterface
{
    public function execute(Payment $payment): void;
    public function getName(): string;
}

abstract class BasePaymentWorkflow
{
    protected readonly \DateTimeImmutable $startedAt;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function process(string $paymentId): void
    {
        $payment = $this->findPayment($paymentId);
        $this->validatePayment($payment);
        $this->logger->info("Starting payment workflow", ['payment_id' => $paymentId]);

        foreach ($this->getSteps() as $step) {
            $this->executeStep($step, $payment);
        }

        $this->completeWorkflow($payment);
        $this->logger->info("Payment workflow completed", ['payment_id' => $paymentId]);
    }

    protected function executeStep(PaymentWorkflowStepInterface $step, Payment $payment): void
    {
        $this->logger->debug("Executing step: {$step->getName()}", ['payment_id' => $payment->getId()->toString()]);
        $step->execute($payment);
    }

    protected function recordAuditEvent(Payment $payment, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'payment_id' => $payment->getId()->toString(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }

    abstract protected function findPayment(string $paymentId): Payment;
    abstract protected function validatePayment(Payment $payment): void;
    abstract protected function getSteps(): array;
    abstract protected function completeWorkflow(Payment $payment): void;
}

final class CreditCardPaymentWorkflow extends BasePaymentWorkflow
{
    protected function findPayment(string $paymentId): Payment { throw new \RuntimeException('Not implemented'); }
    protected function validatePayment(Payment $payment): void { }
    protected function getSteps(): array { return []; }
    protected function completeWorkflow(Payment $payment): void { }
}
final class AchPaymentWorkflow extends BasePaymentWorkflow
{
    protected function findPayment(string $paymentId): Payment { throw new \RuntimeException('Not implemented'); }
    protected function validatePayment(Payment $payment): void { }
    protected function getSteps(): array { return []; }
    protected function completeWorkflow(Payment $payment): void { }
}
final class WireTransferPaymentWorkflow extends BasePaymentWorkflow
{
    protected function findPayment(string $paymentId): Payment { throw new \RuntimeException('Not implemented'); }
    protected function validatePayment(Payment $payment): void { }
    protected function getSteps(): array { return []; }
    protected function completeWorkflow(Payment $payment): void { }
}
