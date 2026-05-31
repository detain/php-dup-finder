<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\ApiClient;
use App\Repository\AccessTokenRepository;
use App\Repository\ApiClientRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ApiAccessTokenManager
{
    private const TOKEN_TIMEOUT = 1800;
    private const ABSOLUTE_TIMEOUT = 604800;

    public function __construct(
        private readonly AccessTokenRepository $tokenRepository,
        private readonly ApiClientRepository $clientRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function validateAccessToken(Request $request): ?ApiClient
    {
        $accessToken = $request->headers->get('Authorization');

        if ($accessToken === null) {
            $this->logger->debug('No access token provided');
            return null;
        }

        $tokenValue = str_replace('Bearer ', '', $accessToken);

        $token = $this->tokenRepository->findActiveByValue($tokenValue);

        if ($token === null) {
            $this->logger->debug('Access token not found or expired', ['token_prefix' => substr($tokenValue, 0, 8)]);
            return null;
        }

        if ($token->isExpired()) {
            $this->logger->info('Access token expired', ['token_id' => $token->getId()]);
            $this->tokenRepository->markExpired($token);
            return null;
        }

        $lastUsed = $token->getLastUsedAt();
        if ($lastUsed !== null) {
            $secondsSinceUsed = time() - $lastUsed->getTimestamp();

            if ($secondsSinceUsed > self::TOKEN_TIMEOUT) {
                $this->logger->info('Access token timed out due to inactivity', [
                    'token_id' => $token->getId(),
                    'seconds_idle' => $secondsSinceUsed,
                ]);
                $this->tokenRepository->markExpired($token);
                return null;
            }
        }

        $createdAt = $token->getCreatedAt();
        $absoluteAge = time() - $createdAt->getTimestamp();
        if ($absoluteAge > self::ABSOLUTE_TIMEOUT) {
            $this->logger->info('Access token reached absolute timeout', [
                'token_id' => $token->getId(),
                'age_seconds' => $absoluteAge,
            ]);
            $this->tokenRepository->markExpired($token);
            return null;
        }

        $client = $this->clientRepository->find($token->getClientId());
        if ($client === null || !$client->isActive()) {
            $this->logger->warning('API client not found or inactive for token', [
                'token_id' => $token->getId(),
                'client_id' => $token->getClientId(),
            ]);
            return null;
        }

        $token->recordUsage();
        $this->tokenRepository->save($token);

        $this->logger->debug('Access token validated successfully', [
            'token_id' => $token->getId(),
            'client_id' => $client->getId(),
        ]);

        return $client;
    }

    public function createAccessToken(ApiClient $client, Request $request): string
    {
        $accessToken = bin2hex(random_bytes(32));

        $token = new AccessToken();
        $token->setClientId($client->getId());
        $token->setValue($accessToken);
        $token->setIpAddress($request->getClientIp());
        $token->setUserAgent($request->headers->get('User-Agent', 'Unknown'));
        $token->setCreatedAt(new \DateTimeImmutable());
        $token->setLastUsedAt(new \DateTimeImmutable());

        $this->tokenRepository->save($token);

        $this->logger->info('Access token created', [
            'client_id' => $client->getId(),
            'token_id' => $token->getId(),
            'ip' => $request->getClientIp(),
        ]);

        return $accessToken;
    }

    public function revokeAccessToken(string $tokenValue): bool
    {
        $token = $this->tokenRepository->findActiveByValue($tokenValue);

        if ($token === null) {
            return false;
        }

        $this->tokenRepository->markExpired($token);

        $this->logger->info('Access token revoked', [
            'token_id' => $token->getId(),
            'client_id' => $token->getClientId(),
        ]);

        return true;
    }
}
