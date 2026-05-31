<?php

declare(strict_types=1);

namespace Acme\Shared\Policy;

use Acme\Shared\Model\Session;
use Acme\Shared\Service\RevocationLookup;
use DateTimeImmutable;

final class SessionValidPolicy
{
    public function __construct(
        private RevocationLookup $revocations,
        private ?DateTimeImmutable $clock = null,
    ) {
    }

    public function isValid(Session $session): bool
    {
        if (!$session->signatureVerified()) {
            return false;
        }

        $now = $this->clock ?? new DateTimeImmutable();
        if ($session->expiresAt() <= $now) {
            return false;
        }

        return !$this->revocations->isRevoked($session->id());
    }
}

final class AuthMiddleware
{
    public function __construct(private SessionValidPolicy $policy) {}

    public function ensureValid(Session $s): void
    {
        if (!$this->policy->isValid($s)) {
            throw new \DomainException('Session expired or revoked.');
        }
    }
}

final class BackgroundActionWorker
{
    public function __construct(private SessionValidPolicy $policy) {}

    public function canRun(Session $s): bool
    {
        return $this->policy->isValid($s);
    }
}
