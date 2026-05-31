<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\TenantService;
use App\Exception\TenantException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class TenantMiddleware
{
    public function __construct(
        private readonly TenantService $tenantService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $tenantId = $request->getHeaderLine('X-Tenant-ID');

        if (empty($tenantId)) {
            $tenantId = $request->getAttribute('user')?->getTenantId();

            if ($tenantId === null) {
                throw new TenantException('No tenant identifier provided');
            }
        }

        try {
            $tenant = $this->tenantService->resolveTenant($tenantId);

            $request = $request->withAttribute('tenant', $tenant);
            $request = $request->withAttribute('tenant_id', $tenant->getId());

            $this->logger->debug('Tenant resolved', [
                'tenant_id' => $tenant->getId(),
                'path' => $request->getUri()->getPath(),
            ]);

            return $next($request);
        } catch (\Exception $e) {
            $this->logger->error('Tenant resolution failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
                'path' => $request->getUri()->getPath(),
            ]);

            throw new TenantException('Invalid or inactive tenant');
        }
    }
}
