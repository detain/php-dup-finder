<?php
declare(strict_types=1);

namespace App\Webhooks\GitHub;

final class GitHubVerifier
{
    public function __construct(private string $secret) {}

    public function verify(string $body, string $signatureHeader): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $body, $this->secret);
        return hash_equals($expected, $signatureHeader);
    }
}

final class GitHubRouter
{
    /** @return callable(array): void */
    public function routeFor(string $eventType): callable
    {
        return match ($eventType) {
            'push' => fn(array $p) => $this->onPush($p),
            'pull_request' => fn(array $p) => $this->onPullRequest($p),
            default => fn(array $p) => null,
        };
    }

    private function onPush(array $payload): void
    {
        error_log('gh push ' . ($payload['ref'] ?? '?'));
    }

    private function onPullRequest(array $payload): void
    {
        error_log('gh pr ' . ($payload['number'] ?? '?'));
    }
}

final class GitHubReceiver
{
    public function __construct(private GitHubVerifier $verifier, private GitHubRouter $router) {}

    public function receive(string $rawBody, array $headers): int
    {
        $sig = (string) ($headers['X-Hub-Signature-256'] ?? '');
        if (!$this->verifier->verify($rawBody, $sig)) {
            return 401;
        }
        $payload = json_decode($rawBody, true) ?: [];
        $eventType = (string) ($headers['X-GitHub-Event'] ?? '');
        $handler = $this->router->routeFor($eventType);
        $handler($payload);
        return 200;
    }
}
