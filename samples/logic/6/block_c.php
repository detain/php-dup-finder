<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\OAuthClient;
use App\Repository\OAuthClientRepository;
use App\Service\TokenGenerator;
use App\Service\ScopeValidator;
use Psr\Log\LoggerInterface;

final class OAuthAuthenticationService
{
    public function __construct(
        private readonly OAuthClientRepository $clientRepository,
        private readonly TokenGenerator $tokenGenerator,
        private readonly ScopeValidator $scopeValidator,
        private readonly LoggerInterface $logger,
    ) {}

    public function authorizeClient(string $clientId, int $userId, array $requestedScopes): array
    {
        $client = $this->clientRepository->findByClientId($clientId);

        if ($client === null) {
            throw new \RuntimeException('OAuth client not found');
        }

        if ($client->getStatus() === 'disabled') {
            throw new \InvalidArgumentException('OAuth client is disabled');
        }

        if ($client->getStatus() === 'pending_approval') {
            throw new \InvalidArgumentException('OAuth client is pending approval');
        }

        if ($client->getStatus() !== 'active') {
            throw new \InvalidArgumentException('OAuth client is not active');
        }

        $user = $this->loadUser($userId);

        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        if ($user->getStatus() === 'locked') {
            throw new \InvalidArgumentException('User account is locked');
        }

        if ($user->getStatus() === 'suspended') {
            throw new \InvalidArgumentException('User account is suspended');
        }

        if ($user->getStatus() !== 'active') {
            throw new \InvalidArgumentException('User account must be active');
        }

        if (empty($requestedScopes)) {
            throw new \InvalidArgumentException('At least one scope must be requested');
        }

        $allowedScopes = $client->getAllowedScopes();
        $invalidScopes = array_diff($requestedScopes, $allowedScopes);

        if (!empty($invalidScopes)) {
            throw new \InvalidArgumentException('Invalid scopes requested: ' . implode(', ', $invalidScopes));
        }

        if (!$this->scopeValidator->validate($requestedScopes)) {
            throw new \InvalidArgumentException('Scope validation failed');
        }

        if ($client->getScopesContainAdmin() && !in_array('admin', $requestedScopes, true)) {
            $this->logger->warning('Client requesting non-admin but has admin scope', [
                'client_id' => $clientId,
            ]);
        }

        $accessToken = $this->tokenGenerator->generateAccessToken();
        $refreshToken = $this->tokenGenerator->generateRefreshToken();

        $this->storeAuthorization([
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => $requestedScopes,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => new \DateTimeImmutable('+1 hour'),
        ]);

        $this->logger->info('OAuth client authorized', [
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => $requestedScopes,
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scopes' => $requestedScopes,
        ];
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $authorization = $this->findAuthorizationByRefreshToken($refreshToken);

        if ($authorization === null) {
            throw new \InvalidArgumentException('Invalid refresh token');
        }

        if ($authorization->isExpired()) {
            throw new \InvalidArgumentException('Refresh token has expired');
        }

        $client = $this->clientRepository->findById($authorization->getClientId());

        if ($client === null || $client->getStatus() !== 'active') {
            throw new \InvalidArgumentException('OAuth client is not valid');
        }

        $user = $this->loadUser($authorization->getUserId());

        if ($user === null || $user->getStatus() !== 'active') {
            throw new \InvalidArgumentException('User is not valid');
        }

        $newAccessToken = $this->tokenGenerator->generateAccessToken();
        $newRefreshToken = $this->tokenGenerator->generateRefreshToken();

        $authorization->setAccessToken($newAccessToken);
        $authorization->setRefreshToken($newRefreshToken);
        $authorization->setExpiresAt(new \DateTimeImmutable('+1 hour'));

        $this->saveAuthorization($authorization);

        $this->logger->info('OAuth tokens refreshed', [
            'client_id' => $client->getClientId(),
            'user_id' => $user->getId(),
        ]);

        return [
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ];
    }

    private function loadUser(int $userId): ?object
    {
        return $this->userRepository->findById($userId);
    }

    private function storeAuthorization(array $data): void
    {
    }

    private function findAuthorizationByRefreshToken(string $token): ?object
    {
        return null;
    }

    private function saveAuthorization(object $authorization): void
    {
    }
}
