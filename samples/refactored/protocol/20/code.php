<?php
declare(strict_types=1);

namespace App\Api\Versioning;

use Symfony\Component\HttpFoundation\Request;

final class ApiVersionResolver
{
    private array $supportedVersions;
    private string $defaultVersion;

    public function __construct(
        array $supportedVersions = ['1.0', '1.1', '2.0'],
        string $defaultVersion = '2.0'
    ) {
        $this->supportedVersions = $supportedVersions;
        $this->defaultVersion = $defaultVersion;
    }

    public function resolveVersion(Request $request): string
    {
        $version = $request->headers->get('API-Version') ?? 
                   $request->headers->get('Api-Version') ?? 
                   $request->headers->get('api-version') ?? 
                   $this->defaultVersion;
        
        return trim($version);
    }

    public function isSupported(string $version): bool
    {
        return in_array($version, $this->supportedVersions, true);
    }

    public function negotiateContentType(Request $request): string
    {
        $accept = $request->headers->get('Accept', 'application/json');
        
        if (str_contains($accept, 'application/xml')) {
            return 'application/xml';
        }
        
        return 'application/json';
    }

    public function getSupportedVersions(): array
    {
        return $this->supportedVersions;
    }
}

trait ApiVersioningTrait
{
    private ApiVersionResolver $versionResolver;
    private LoggerInterface $logger;

    protected function extractApiVersion(Request $request): string
    {
        return $this->versionResolver->resolveVersion($request);
    }

    protected function validateApiVersion(string $version): bool
    {
        return $this->versionResolver->isSupported($version);
    }

    protected function createVersionErrorResponse(string $version): JsonResponse
    {
        $this->logger->warning('Unsupported API version requested', [
            'version' => $version,
            'supported' => $this->versionResolver->getSupportedVersions(),
        ]);
        
        return new JsonResponse([
            'error' => 'Unsupported API version',
            'requested_version' => $version,
            'supported_versions' => $this->versionResolver->getSupportedVersions(),
        ], 406);
    }

    protected function getContentType(Request $request): string
    {
        return $this->versionResolver->negotiateContentType($request);
    }
}
