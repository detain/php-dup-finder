<?php
declare(strict_types=1);

namespace App\Cqrs\Payments;

final class RefundPaymentCommand
{
    public function __construct(public string $chargeId, public int $amountCents) {}
}

final class RefundPaymentResult
{
    public function __construct(public string $refundId, public bool $wasAlreadyRefunded) {}
}

final class RefundPaymentHandler
{
    public function __construct(private \PDO $pdo, private \Psr\Log\LoggerInterface $log) {}

    public function handle(RefundPaymentCommand $cmd): RefundPaymentResult
    {
        $this->log->info('handling RefundPayment', ['charge' => $cmd->chargeId]);
        $stmt = $this->pdo->prepare('SELECT refund_id FROM payments WHERE charge_id = ?');
        $stmt->execute([$cmd->chargeId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false && $existing !== null) {
            return new RefundPaymentResult((string) $existing, true);
        }
        $refundId = 're_' . bin2hex(random_bytes(8));
        $ins = $this->pdo->prepare('UPDATE payments SET refund_id = ?, refunded_cents = ? WHERE charge_id = ?');
        $ins->execute([$refundId, $cmd->amountCents, $cmd->chargeId]);
        $this->log->info('payment refunded', ['refund' => $refundId]);
        return new RefundPaymentResult($refundId, false);
    }
}

final class PaymentBus
{
    public function __construct(private RefundPaymentHandler $handler) {}

    public function dispatch(RefundPaymentCommand $cmd): RefundPaymentResult
    {
        try {
            return $this->handler->handle($cmd);
        } catch (\Throwable $e) {
            throw new \RuntimeException('RefundPayment failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
