<?php
declare(strict_types=1);

namespace App\Webhooks\Stripe;

final class StripeVerifier
{
    public function __construct(private string $secret) {}

    public function verify(string $body, string $signatureHeader): bool
    {
        $expected = hash_hmac('sha256', $body, $this->secret);
        return hash_equals($expected, $signatureHeader);
    }
}

final class StripeRouter
{
    /** @return callable(array): void */
    public function routeFor(string $eventType): callable
    {
        return match ($eventType) {
            'charge.succeeded' => fn(array $p) => $this->onCharge($p),
            'invoice.paid' => fn(array $p) => $this->onInvoice($p),
            default => fn(array $p) => null,
        };
    }

    private function onCharge(array $payload): void
    {
        error_log('stripe charge ' . ($payload['id'] ?? '?'));
    }

    private function onInvoice(array $payload): void
    {
        error_log('stripe invoice ' . ($payload['id'] ?? '?'));
    }
}

final class StripeReceiver
{
    public function __construct(private StripeVerifier $verifier, private StripeRouter $router) {}

    public function receive(string $rawBody, array $headers): int
    {
        $sig = (string) ($headers['Stripe-Signature'] ?? '');
        if (!$this->verifier->verify($rawBody, $sig)) {
            return 401;
        }
        $payload = json_decode($rawBody, true) ?: [];
        $handler = $this->router->routeFor((string) ($payload['type'] ?? ''));
        $handler($payload['data'] ?? []);
        return 200;
    }
}
