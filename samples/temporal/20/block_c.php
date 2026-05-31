<?php
declare(strict_types=1);

namespace Cloudflare\WAF\Service;

use Cloudflare\WAF\Repository\RuleRepository;
use Cloudflare\WAF\Repository\FirewallRuleRepository;
use Cloudflare\WAF\Repository\LockRepository;
use Cloudflare\WAF\Entity\WafRule;
use Cloudflare\WAF\Entity\FirewallRule;
use Cloudflare\WAF\Entity\RuleGroup;
use CloudCloudflare\WAF\Exception\WafException;
use Cloudflare\WAF\Service\Matching\RuleMatcher;
use Psr\Log\LoggerInterface;

final class FirewallRuleService
{
    private RuleRepository $ruleRepo;
    private FirewallRuleRepository $firewallRepo;
    private LockRepository $lockRepo;
    private RuleMatcher $ruleMatcher;
    private LoggerInterface $logger;

    public function __construct(
        RuleRepository $ruleRepo,
        FirewallRuleRepository $firewallRepo,
        LockRepository $lockRepo,
        RuleMatcher $ruleMatcher,
        LoggerInterface $logger
    ) {
        $this->ruleRepo = $ruleRepo;
        $this->firewallRepo = $firewallRepo;
        $this->lockRepo = $lockRepo;
        $this->ruleMatcher = $ruleMatcher;
        $this->logger = $logger;
    }

    public function createFirewallRule(string $zoneId, array $ruleData): FirewallRuleResult
    {
        $this->logger->info('Creating firewall rule', [
            'zone_id' => $zoneId,
            'description' => $ruleData['description'] ?? 'Untitled'
        ]);

        $zone = $this->ruleRepo->findZone($zoneId);
        if ($zone === null) {
            throw new WafException("Zone not found: {$zoneId}");
        }

        if (!$zone->isWafEnabled()) {
            throw new WafException("WAF is not enabled for zone: {$zoneId}");
        }

        $ruleLock = $this->firewallRepo->acquireRuleLock($zoneId);
        if ($ruleLock === null) {
            throw new WafException("Could not acquire firewall rule lock for zone: {$zoneId}");
        }

        $this->logger->debug('Firewall rule lock acquired', ['zone_id' => $zoneId]);

        try {
            $this->validateRuleExpression($ruleData['expression']);

            $rule = WafRule::create([
                'zone_id' => $zoneId,
                'description' => $ruleData['description'],
                'expression' => $ruleData['expression'],
                'action' => $ruleData['action'],
                'priority' => $ruleData['priority'] ?? 1,
                'status' => 'inactive',
                'created_at' => new \DateTimeImmutable()
            ]);

            $savedRule = $this->firewallRepo->save($rule);
            $this->logger->debug('Firewall rule record created', [
                'rule_id' => $savedRule->getId()
            ]);

            if ($ruleData.get('groups')) {
                foreach ($ruleData['groups'] as $groupId) {
                    $group = $this->ruleRepo->findGroup($groupId);
                    if ($group !== null) {
                        $this->firewallRepo->attachRuleToGroup($savedRule->getId(), $groupId);
                    }
                }
            }

            $affectedRules = $this->ruleMatcher->findConflictingRules($savedRule);
            if (count($affectedRules) > 0) {
                $this->logger->warning('Rule may conflict with existing rules', [
                    'rule_id' => $savedRule->getId(),
                    'conflicting_count' => count($affectedRules)
                ]);
            }

            $this->firewallRepo->releaseRuleLock($ruleLock);

            $this->logger->info('Firewall rule created successfully', [
                'rule_id' => $savedRule->getId(),
                'zone_id' => $zoneId
            ]);

            return new FirewallRuleResult([
                'success' => true,
                'rule_id' => $savedRule->getId(),
                'conflicts' => array_map(fn($r) => $r->getId(), $affectedRules)
            ]);

        } catch (\Throwable $e) {
            $this->firewallRepo->releaseRuleLock($ruleLock);
            $this->logger->error('Firewall rule creation failed', [
                'zone_id' => $zoneId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function deployFirewallRule(string $ruleId): DeployResult
    {
        $rule = $this->firewallRepo->findById($ruleId);
        if ($rule === null) {
            throw new WafException("Rule not found: {$ruleId}");
        }

        if ($rule->getStatus() === 'active') {
            return new DeployResult([
                'success' => true,
                'rule_id' => $ruleId,
                'already_active' => true
            ]);
        }

        $deployLock = $this->firewallRepo->acquireDeployLock($ruleId);
        if ($deployLock === null) {
            throw new WafException("Could not acquire deploy lock for rule: {$ruleId}");
        }

        try {
            $this->firewallRepo->updateStatus($ruleId, 'deploying');

            $deployment = $this->firewallRepo->pushToCloudflare($rule);

            if (!$deployment->isSuccess()) {
                $this->firewallRepo->updateStatus($ruleId, 'failed');
                throw new WafException('Rule deployment to Cloudflare failed');
            }

            $this->firewallRepo->updateStatus($ruleId, 'active', [
                'deployed_at' => new \DateTimeImmutable(),
                'cloudflare_rule_id' => $deployment->getCloudflareId()
            ]);

            $this->firewallRepo->releaseDeployLock($deployLock);

            $this->logger->info('Firewall rule deployed successfully', [
                'rule_id' => $ruleId,
                'cloudflare_id' => $deployment->getCloudflareId()
            ]);

            return new DeployResult([
                'success' => true,
                'rule_id' => $ruleId,
                'cloudflare_id' => $deployment->getCloudflareId()
            ]);

        } catch (\Throwable $e) {
            $this->firewallRepo->releaseDeployLock($deployLock);
            $this->logger->error('Rule deployment failed', [
                'rule_id' => $ruleId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function deleteFirewallRule(string $ruleId): DeleteResult
    {
        $rule = $this->firewallRepo->findById($ruleId);
        if ($rule === null) {
            throw new WafException("Rule not found: {$ruleId}");
        }

        if ($rule->getStatus() === 'deleting') {
            throw new WafException('Rule is already being deleted');
        }

        $deleteLock = $this->firewallRepo->acquireDeleteLock($ruleId);
        if ($deleteLock === null) {
            throw new WafException("Could not acquire delete lock for rule: {$ruleId}");
        }

        try {
            $this->firewallRepo->updateStatus($ruleId, 'deleting');

            if ($rule->isActive()) {
                $deletion = $this->firewallRepo->removeFromCloudflare($rule);
                if (!$deletion->isSuccess()) {
                    throw new WafException('Failed to remove rule from Cloudflare');
                }
            }

            $this->firewallRepo->detachFromGroups($ruleId);
            $this->firewallRepo->delete($ruleId);

            $this->firewallRepo->releaseDeleteLock($deleteLock);

            $this->logger->info('Firewall rule deleted successfully', [
                'rule_id' => $ruleId
            ]);

            return new DeleteResult([
                'success' => true,
                'rule_id' => $ruleId,
                'deleted_at' => (new \DateTimeImmutable())->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->firewallRepo->releaseDeleteLock($deleteLock);
            $this->logger->error('Rule deletion failed', [
                'rule_id' => $ruleId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function validateRuleExpression(string $expression): void
    {
        if (empty($expression)) {
            throw new WafException('Rule expression cannot be empty');
        }

        if (strlen($expression) > 2000) {
            throw new WafException('Rule expression exceeds maximum length of 2000 characters');
        }

        $validOperators = ['eq', 'ne', 'contains', 'starts_with', 'ends_with', 'matches', 'in'];
        foreach ($validOperators as $op) {
            if (stripos($expression, $op) !== false) {
                return;
            }
        }

        throw new WafException('Rule expression contains no recognized operators');
    }
}
