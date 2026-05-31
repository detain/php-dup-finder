<?php
declare(strict_types=1);

namespace App\Webhooks\Slack;

final class SlackVerifier
{
    public function __construct(private string $secret) {}

    public function verify(string $body, string $signatureHeader): bool
    {
        $expected = 'v0=' . hash_hmac('sha256', $body, $this->secret);
        return hash_equals($expected, $signatureHeader);
    }
}

final class SlackRouter
{
    /** @return callable(array): void */
    public function routeFor(string $eventType): callable
    {
        return match ($eventType) {
            'message' => fn(array $p) => $this->onMessage($p),
            'app_mention' => fn(array $p) => $this->onMention($p),
            default => fn(array $p) => null,
        };
    }

    private function onMessage(array $payload): void
    {
        error_log('slack message ' . ($payload['text'] ?? ''));
    }

    private function onMention(array $payload): void
    {
        error_log('slack mention ' . ($payload['user'] ?? '?'));
    }
}

final class SlackReceiver
{
    public function __construct(private SlackVerifier $verifier, private SlackRouter $router) {}

    public function receive(string $rawBody, array $headers): int
    {
        $sig = (string) ($headers['X-Slack-Signature'] ?? '');
        if (!$this->verifier->verify($rawBody, $sig)) {
            return 401;
        }
        $payload = json_decode($rawBody, true) ?: [];
        $eventType = (string) ($payload['event']['type'] ?? '');
        $handler = $this->router->routeFor($eventType);
        $handler($payload['event'] ?? []);
        return 200;
    }
}
