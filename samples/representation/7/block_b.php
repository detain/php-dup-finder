<?php
declare(strict_types=1);

namespace Security\Audit\Webhook;

final class AuditWebhookPayload
{
    /** @var array<string, mixed> */
    public array $body;
    public string $signature;

    public function __construct(array $event, string $secret)
    {
        if (empty($event['actor_id']) || empty($event['action'])) {
            throw new \InvalidArgumentException('Actor/action required');
        }
        if (!in_array($event['severity'] ?? 'info', ['info', 'warn', 'error', 'critical'], true)) {
            throw new \InvalidArgumentException('Invalid severity');
        }
        $this->body = [
            'event_type' => 'audit.' . (string)$event['action'],
            'actor' => [
                'id' => (string)$event['actor_id'],
                'name' => (string)($event['actor_label'] ?? ''),
            ],
            'target' => [
                'type' => (string)($event['target_type'] ?? ''),
                'id' => (string)($event['target_id'] ?? ''),
            ],
            'severity' => (string)($event['severity'] ?? 'info'),
            'context' => $event['context'] ?? null,
            'occurred_at' => (string)($event['at'] ?? gmdate('c')),
            'version' => '1.0',
        ];
        $this->signature = hash_hmac('sha256', json_encode($this->body, JSON_THROW_ON_ERROR), $secret);
    }

    public function asJson(): string
    {
        return json_encode($this->body, JSON_THROW_ON_ERROR);
    }
}

final class WebhookDispatcher
{
    public function dispatch(AuditWebhookPayload $payload, string $url): bool
    {
        // imagine curl_exec ...
        return strlen($payload->signature) === 64;
    }
}
