<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
use App\Service\SignatureVerifier;
use App\Service\ApiRateLimiter;
use Psr\Log\LoggerInterface;

final class ApiKeyAuthenticationService
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly SignatureVerifier $signatureVerifier,
        private readonly ApiRateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {}

    public function authenticateRequest(string $apiKeyId, string $signature, int $timestamp): bool
    {
        if (empty($apiKeyId) || empty($signature)) {
            throw new \InvalidArgumentException('API key and signature are required');
        }

        if ($timestamp < time() - 300 || $timestamp > time() + 300) {
            throw new \InvalidArgumentException('Request timestamp is invalid or expired');
        }

        $apiKey = $this->apiKeyRepository->findById($apiKeyId);

        if ($apiKey === null) {
            $this->logger->warning('API authentication failed - key not found', [
                'api_key_id' => $apiKeyId,
            ]);
            throw new \InvalidArgumentException('Invalid API key');
        }

        if ($apiKey->getStatus() === 'revoked') {
            $this->logger->warning('API authentication failed - key revoked', [
                'api_key_id' => $apiKeyId,
            ]);
            throw new \InvalidArgumentException('API key has been revoked');
        }

        if ($apiKey->getStatus() === 'expired') {
            $this->logger->warning('API authentication failed - key expired', [
                'api_key_id' => $apiKeyId,
            ]);
            throw new \InvalidArgumentException('API key has expired');
        }

        if ($apiKey->getStatus() !== 'active') {
            $this->logger->warning('API authentication failed - invalid status', [
                'api_key_id' => $apiKeyId,
                'status' => $apiKey->getStatus(),
            ]);
            throw new \InvalidArgumentException('API key is not active');
        }

        if (!$this->rateLimiter->isAllowed($apiKeyId)) {
            $this->logger->warning('API authentication failed - rate limit', [
                'api_key_id' => $apiKeyId,
            ]);
            throw new \InvalidArgumentException('Rate limit exceeded');
        }

        if (!$this->signatureVerifier->verify($apiKeyId, $signature, $timestamp)) {
            $this->logger->warning('API authentication failed - invalid signature', [
                'api_key_id' => $apiKeyId,
            ]);
            throw new \InvalidArgumentException('Invalid signature');
        }

        if ($apiKey->hasExpired()) {
            $this->logger->warning('API authentication failed - key past expiry', [
                'api_key_id' => $apiKeyId,
            ]);
            throw new \InvalidArgumentException('API key has expired');
        }

        $apiKey->setLastUsedAt(new \DateTimeImmutable());
        $this->apiKeyRepository->save($apiKey);

        $this->rateLimiter->recordRequest($apiKeyId);

        $this->logger->info('API request authenticated', [
            'api_key_id' => $apiKeyId,
        ]);

        return true;
    }

    public function createApiKey(int $ownerId, string $name, array $scopes): array
    {
        $owner = $this->loadOwner($ownerId);

        if ($owner === null) {
            throw new \RuntimeException('Owner not found');
        }

        if ($owner->getStatus() === 'suspended') {
            throw new \InvalidArgumentException('Cannot create API keys for suspended accounts');
        }

        if ($owner->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Owner account must be active');
        }

        if (empty($name)) {
            throw new \InvalidArgumentException('API key name is required');
        }

        if (empty($scopes)) {
            throw new \InvalidArgumentException('At least one scope is required');
        }

        if (!$this->validateScopes($scopes)) {
            throw new \InvalidArgumentException('Invalid scopes provided');
        }

        $apiKey = new ApiKey();
        $apiKey->setOwnerId($ownerId);
        $apiKey->setName($name);
        $apiKey->setScopes($scopes);
        $apiKey->setKey($this->generateApiKey());
        $apiKey->setStatus('active');
        $apiKey->setCreatedAt(new \DateTimeImmutable());
        $apiKey->setExpiresAt(new \DateTimeImmutable('+1 year'));

        $this->apiKeyRepository->save($apiKey);

        $this->logger->info('API key created', [
            'api_key_id' => $apiKey->getId(),
            'owner_id' => $ownerId,
        ]);

        return [
            'id' => $apiKey->getId(),
            'key' => $apiKey->getKey(),
            'name' => $name,
            'scopes' => $scopes,
        ];
    }

    private function loadOwner(int $ownerId): ?object
    {
        return $this->ownerRepository->findById($ownerId);
    }

    private function validateScopes(array $scopes): bool
    {
        $validScopes = ['read', 'write', 'delete', 'admin'];

        foreach ($scopes as $scope) {
            if (!in_array($scope, $validScopes, true)) {
                return false;
            }
        }

        return true;
    }

    private function generateApiKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
