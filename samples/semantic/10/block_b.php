<?php

declare(strict_types=1);

namespace Acme\Ws\Handlers;

use Acme\Ws\Connection;
use Acme\Auth\Adapter\TokenParser;
use Acme\Auth\Repository\SessionStore;
use DateTimeImmutable;

final class WebSocketAuthHandler
{
    public function __construct(
        private TokenParser $parser,
        private SessionStore $sessions,
    ) {
    }

    public function onConnect(Connection $conn, array $params): bool
    {
        $token = (string) ($params['token'] ?? '');
        $parsed = $this->parser->parse($token);

        if ($parsed === null) {
            $conn->close(4001, 'invalid_token');
            return false;
        }

        $expiry = new DateTimeImmutable('@' . $parsed->expiresAt());
        $now = new DateTimeImmutable();
        $isRevoked = $this->sessions->isRevoked($parsed->id());

        $isValid = $expiry > $now && !$isRevoked;

        if (!$isValid) {
            $conn->close(4002, 'expired_or_revoked');
            return false;
        }

        $conn->bindUser($parsed->subject());
        return true;
    }
}
