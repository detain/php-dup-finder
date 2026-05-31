<?php
declare(strict_types=1);

namespace App\Webhooks;

interface SignatureVerifier
{
    public function verify(string $body, string $signatureHeader): bool;
}

final class HmacVerifier implements SignatureVerifier
{
    public function __construct(
        private string $secret,
        private string $algo,
        private string $prefix = '',
    ) {}

    public function verify(string $body, string $signatureHeader): bool
    {
        $expected = $this->prefix . hash_hmac($this->algo, $body, $this->secret);
        return hash_equals($expected, $signatureHeader);
    }
}

final class Receiver
{
    /**
     * @param array<string, callable(array): void> $handlers
     * @param callable(string, array): string      $eventTypeExtractor
     * @param callable(string, array): array       $payloadExtractor
     */
    public function __construct(
        private SignatureVerifier $verifier,
        private string $signatureHeader,
        private array $handlers,
        private \Closure $eventTypeExtractor,
        private \Closure $payloadExtractor,
    ) {}

    public function receive(string $rawBody, array $headers): int
    {
        if (!$this->verifier->verify($rawBody, (string) ($headers[$this->signatureHeader] ?? ''))) {
            return 401;
        }
        $body = json_decode($rawBody, true) ?: [];
        $event = ($this->eventTypeExtractor)($rawBody, $headers);
        $handler = $this->handlers[$event] ?? fn(array $p) => null;
        $handler(($this->payloadExtractor)($event, $body));
        return 200;
    }
}
