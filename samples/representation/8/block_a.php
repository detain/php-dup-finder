<?php
declare(strict_types=1);

namespace Webhook\Stripe;

final class StripePaymentIntentSucceeded
{
    public string $id;
    public string $object;
    public int $amount_received;
    public string $currency;
    public ?string $customer;
    public string $status;
    public array $metadata;
    public int $created;
    public ?string $latest_charge;

    public function __construct(array $raw)
    {
        if (($raw['type'] ?? '') !== 'payment_intent.succeeded') {
            throw new \InvalidArgumentException('Wrong event type');
        }
        $obj = $raw['data']['object'] ?? null;
        if (!is_array($obj) || empty($obj['id'])) {
            throw new \InvalidArgumentException('Missing event data');
        }
        if (($obj['status'] ?? '') !== 'succeeded') {
            throw new \InvalidArgumentException('Not succeeded');
        }
        $this->id = (string)$obj['id'];
        $this->object = (string)($obj['object'] ?? 'payment_intent');
        $this->amount_received = (int)($obj['amount_received'] ?? 0);
        $this->currency = strtolower((string)($obj['currency'] ?? 'usd'));
        $this->customer = isset($obj['customer']) ? (string)$obj['customer'] : null;
        $this->status = (string)$obj['status'];
        $this->metadata = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
        $this->created = (int)($obj['created'] ?? time());
        $this->latest_charge = isset($obj['latest_charge']) ? (string)$obj['latest_charge'] : null;
    }
}

final class StripeWebhookController
{
    public function handle(array $payload): StripePaymentIntentSucceeded
    {
        return new StripePaymentIntentSucceeded($payload);
    }
}
