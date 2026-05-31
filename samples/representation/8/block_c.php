<?php
declare(strict_types=1);

namespace Queue\Jobs;

final class PaymentSucceededJob
{
    public string $job_id;
    public string $payment_ref;
    public int $amount_minor_units;
    public string $currency_iso;
    public ?string $cust_ref;
    public array $meta;
    public string $occurred_at_iso;
    public ?string $charge_ref;
    public string $idempotency_key;
    public int $attempts = 0;

    public function fromEvent(array $event): void
    {
        if (empty($event['paymentId'])) {
            throw new \InvalidArgumentException('Need paymentId');
        }
        if (($event['amountCents'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('Need positive amount');
        }
        if (strlen((string)($event['currencyCode'] ?? '')) !== 3) {
            throw new \InvalidArgumentException('Need ISO-4217');
        }
        $this->job_id = bin2hex(random_bytes(8));
        $this->payment_ref = (string)$event['paymentId'];
        $this->amount_minor_units = (int)$event['amountCents'];
        $this->currency_iso = strtoupper((string)$event['currencyCode']);
        $this->cust_ref = isset($event['customerRef']) ? (string)$event['customerRef'] : null;
        $this->meta = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        $this->occurred_at_iso = (string)($event['occurredAt'] ?? gmdate('c'));
        $this->charge_ref = isset($event['chargeRef']) ? (string)$event['chargeRef'] : null;
        $this->idempotency_key = hash('sha256', $this->payment_ref . '|' . $this->amount_minor_units);
    }

    public function toJson(): string
    {
        return json_encode([
            'job_id' => $this->job_id,
            'payment_ref' => $this->payment_ref,
            'amount' => $this->amount_minor_units,
            'currency' => $this->currency_iso,
            'idempotency_key' => $this->idempotency_key,
        ], JSON_THROW_ON_ERROR);
    }
}

final class PaymentQueue
{
    public function enqueue(PaymentSucceededJob $job): bool
    {
        return strlen($job->toJson()) > 0;
    }
}
