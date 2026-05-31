<?php
declare(strict_types=1);

namespace Rackspace\CDN\Service;

use Rackspace\CDN\Repository\ServiceRepository;
use Rackspace\CDN\Repository\OriginRepository;
use Rackspace\CDN\Repository\CacheRuleRepository;
use Rackspace\CDN\Entity\CdnService;
use Rackspace\CDN\Entity\Origin;
use Rackspace\CDN\Entity\CacheRule;
use Rackspace\CDN\Exception\CDNException;
use Rackspace\CDN\Service\Provisioning\ServiceProvisioner;
use Psr\Log\LoggerInterface;

final class CDNServiceLifecycleService
{
    private ServiceRepository $serviceRepo;
    private OriginRepository $originRepo;
    private CacheRuleRepository $cacheRuleRepo;
    private ServiceProvisioner $provisioner;
    private LoggerInterface $logger;

    public function __construct(
        ServiceRepository $serviceRepo,
        OriginRepository $originRepo,
        CacheRuleRepository $cacheRuleRepo,
        ServiceProvisioner $provisioner,
        LoggerInterface $logger
    ) {
        $this->serviceRepo = $serviceRepo;
        $this->originRepo = $originRepo;
        $this->cacheRuleRepo = $cacheRuleRepo;
        $this->provisioner = $provisioner;
        $this->logger = $logger;
    }

    public function createService(array $serviceData): ServiceCreationResult
    {
        $this->logger->info('Creating CDN service', [
            'name' => $serviceData['name'] ?? 'unknown'
        ]);

        $domainName = $serviceData['name'] . '.cdn.example.com';

        $existing = $this->serviceRepo->findByDomain($domainName);
        if ($existing !== null) {
            throw new CDNException("CDN service already exists for domain: {$domainName}");
        }

        $serviceLock = $this->serviceRepo->acquireProvisioningLock($domainName);
        if ($serviceLock === null) {
            throw new CDNException("Could not acquire provisioning lock for: {$domainName}");
        }

        $this->logger->debug('Service provisioning lock acquired', ['domain' => $domainName]);

        try {
            $service = CdnService::create([
                'name' => $serviceData['name'],
                'domain_name' => $domainName,
                'status' => 'creating',
                'origin_protocol' => $serviceData['origin_protocol'] ?? 'https',
                'cname_alias' => $serviceData['cname'] ?? null,
                'created_at' => new \DateTimeImmutable()
            ]);

            $savedService = $this->serviceRepo->save($service);
            $this->logger->debug('CDN service record created', ['service_id' => $savedService->getId()]);

            $origins = [];
            foreach ($serviceData['origins'] as $originData) {
                $origin = Origin::create([
                    'service_id' => $savedService->getId(),
                    'origin_url' => $originData['url'],
                    'port' => $originData['port'] ?? 80,
                    'ssl_enabled' => $originData['ssl_enabled'] ?? false,
                    'priority' => $originData['priority'] ?? 1,
                    'status' => 'active'
                ]);

                $savedOrigin = $this->originRepo->save($origin);
                $origins[] = $savedOrigin;
            }

            $this->logger->debug('Origins created', ['count' => count($origins)]);

            $cacheRules = [];
            $defaultRules = [
                ['path' => '/*', 'ttl' => 3600, 'behavior' => 'cache'],
                ['path' => '/*.html', 'ttl' => 300, 'behavior' => 'cache'],
                ['path' => '/*.json', 'ttl' => 600, 'behavior' => 'cache']
            ];

            foreach ($serviceData.get('cache_rules', $defaultRules) as $ruleData) {
                $rule = CacheRule::create([
                    'service_id' => $savedService->getId(),
                    'path_pattern' => $ruleData['path'],
                    'ttl' => $ruleData['ttl'],
                    'behavior' => $ruleData['behavior'],
                    'priority' => $ruleData['priority'] ?? 0,
                    'status' => 'active'
                ]);

                $savedRule = $this->cacheRuleRepo->save($rule);
                $cacheRules[] = $savedRule;
            }

            $this->logger->debug('Cache rules created', ['count' => count($cacheRules)]);

            $provisionResult = $this->provisioner->provisionService($savedService, $origins);

            if (!$provisionResult->isSuccess()) {
                throw new CDNException('Service provisioning failed: ' . $provisionResult->getError());
            }

            $this->serviceRepo->updateStatus($savedService->getId(), 'deployed', [
                'deployed_at' => new \DateTimeImmutable(),
                'provisioning_result' => $provisionResult->toArray()
            ]);

            $this->serviceRepo->releaseProvisioningLock($serviceLock);

            $this->logger->info('CDN service created and deployed', [
                'service_id' => $savedService->getId(),
                'domain' => $domainName,
                'origins_count' => count($origins)
            ]);

            return new ServiceCreationResult([
                'success' => true,
                'service_id' => $savedService->getId(),
                'domain_name' => $domainName,
                'status' => 'deployed',
                'origins' => array_map(fn($o) => $o->getOriginUrl(), $origins)
            ]);

        } catch (\Throwable $e) {
            $this->serviceRepo->releaseProvisioningLock($serviceLock);
            $this->logger->error('CDN service creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function purgeCache(string $serviceId, array $paths): PurgeResult
    {
        $service = $this->serviceRepo->findById($serviceId);
        if ($service === null) {
            throw new CDNException("Service not found: {$serviceId}");
        }

        if ($service->getStatus() !== 'deployed') {
            throw new CDNException("Cannot purge cache for service in status: {$service->getStatus()}");
        }

        $purgeLock = $this->serviceRepo->acquirePurgeLock($serviceId);
        if ($purgeLock === null) {
            throw new CDNException('Could not acquire purge lock');
        }

        try {
            $purgeTokens = [];
            foreach ($paths as $path) {
                $token = $this->provisioner->issuePurgeToken($serviceId, $path);
                $purgeTokens[] = [
                    'path' => $path,
                    'token' => $token,
                    'issued_at' => new \DateTimeImmutable()
                ];
            }

            $this->serviceRepo->recordPurge($serviceId, $purgeTokens);

            $this->serviceRepo->releasePurgeLock($purgeLock);

            $this->logger->info('Cache purge initiated', [
                'service_id' => $serviceId,
                'paths_count' => count($paths)
            ]);

            return new PurgeResult([
                'success' => true,
                'service_id' => $serviceId,
                'purged_paths' => count($paths),
                'purge_tokens' => $purgeTokens
            ]);

        } catch (\Throwable $e) {
            $this->serviceRepo->releasePurgeLock($purgeLock);
            $this->logger->error('Cache purge failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
