<?php

declare(strict_types=1);

namespace App\Audit;

use App\Repositories\AuditLogRepository;
use Psr\Log\LoggerInterface;
use DateTimeImmutable;

final class SecurityAuditLogger
{
    public function __construct(
        private AuditLogRepository $repo,
        private LoggerInterface $logger,
    ) {}

    public function loginFailed(int $userId, string $email, string $ip, int $attemptNumber): void
    {
        $event = [
            'event' => 'auth.login.failed',
            'user_id' => $userId,
            'email' => $email,
            'ip' => $ip,
            'attempt' => $attemptNumber,
            'occurred_at' => (new DateTimeImmutable())->format('c'),
        ];

        // Escalate severity once the user is one attempt away from lockout.
        if ($attemptNumber >= 4) {
            $event['severity'] = 'warning';
            $event['message'] = sprintf(
                'User one attempt away from lockout (%d/5 in 15 minutes).',
                $attemptNumber
            );
            $this->logger->warning($event['message'], $event);
        } elseif ($attemptNumber >= 5) {
            // Lockout itself is logged separately by accountLocked() — keep this branch consistent.
            $event['severity'] = 'critical';
        } else {
            $event['severity'] = 'info';
        }

        $this->repo->insert($event);
    }

    public function accountLocked(int $userId, string $email, string $ip): void
    {
        $event = [
            'event' => 'auth.account.locked',
            'user_id' => $userId,
            'email' => $email,
            'ip' => $ip,
            'reason' => '5 failed attempts within 15 minutes',
            'severity' => 'critical',
            'occurred_at' => (new DateTimeImmutable())->format('c'),
        ];
        $this->repo->insert($event);
        $this->logger->critical($event['reason'], $event);
    }
}
