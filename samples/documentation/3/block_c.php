<?php

declare(strict_types=1);

namespace App\Docs\Runbooks;

/**
 * Deployment Runbook Documentation Generator
 *
 * Generates markdown runbook documentation for deployment procedures.
 * These runbooks are stored in docs/runbooks/ and synced to the
 * internal wiki. Source of truth is the YAML configuration below.
 *
 * RUNBOOK STRUCTURE (per DOC-STANDARDS-001):
 * 1. Overview - Purpose and scope of the procedure
 * 2. Prerequisites - What must be in place before starting
 * 3. Pre-deployment Checks - Health checks and validations
 * 4. Deployment Steps - Numbered step-by-step instructions
 * 5. Post-deployment Verification - How to confirm success
 * 6. Rollback Procedures - How to undo if something goes wrong
 * 7. Troubleshooting - Common issues and solutions
 * 8. Contacts - On-call contacts for escalations
 *
 * ENVIRONMENT SPECIFIC NOTES (from ops documentation):
 *
 * PRODUCTION:
 * - Deployment window: Tuesday and Thursday 10:00-14:00 UTC
 * - Required approvals: 2 senior engineers
 * - Monitoring: Enhanced monitoring for 24 hours post-deployment
 * - Backup: Full database backup before migration
 * - Communication: Notification in #deployments, #ops-alerts
 *
 * STAGING:
 * - Deployment window: Any time during business hours (09:00-18:00 UTC)
 * - Required approvals: 1 engineer
 * - Monitoring: Standard monitoring
 * - Backup: Incremental backup
 * - Communication: Notification in #deployments-staging
 *
 * DEPLOYMENT TIMEOUTS:
 * - Pre-deployment checks: 5 minutes maximum
 * - Individual service deployment: 15 minutes maximum
 * - Database migration: 30 minutes maximum
 * - Post-deployment verification: 10 minutes maximum
 * - Total deployment: 60 minutes maximum before auto-rollback
 *
 * ROLLBACK TRIGGERS:
 * - Error rate increase > 5% above baseline
 * - Latency increase > 200% above baseline
 * - HTTP 5xx rate > 1% of requests
 * - Critical pod crash loop
 * - Database migration failure
 *
 * AUTOMATED CHECKS (executed by CI/CD pipeline):
 * - Docker image vulnerability scan (Trivy)
 * - Kubernetes manifest validation (kubeval)
 * - Terraform plan review (for infrastructure changes)
 * - Database migration compatibility check
 * - API contract backward compatibility check
 *
 * MANUAL CHECKS (required before production deployment):
 * - Review changelog for breaking changes
 * - Verify database migration SQL
 * - Confirm feature flags are correctly set
 * - Check dependent service versions are compatible
 * - Verify monitoring dashboards are in place
 *
 * POST-DEPLOYMENT CHECKLIST:
 * [ ] All pods running with new version
 * [ ] Health check endpoints returning 200
 * [ ] Error rates within normal thresholds
 * [ ] Latency within SLA (<200ms p99)
 * [ ] No critical errors in logs
 * [ ] Smoke tests passing
 * [ ] Dashboard metrics looking normal
 * [ ] On-call engineer acknowledges successful deployment
 */
class DeploymentRunbookGenerator
{
    private const RUNBOOK_TEMPLATE = <<<'RUNBOOK'
# {service_name} Deployment Runbook

## Overview

This runbook covers the deployment procedure for the **{service_name}** service
to {environment}. This service {service_description}

## Prerequisites

- [ ] kubectl configured with cluster credentials for {cluster}
- [ ] Helm v3 installed and authenticated
- [ ] Docker images pushed to registry with correct tags
- [ ] Kubernetes secrets configured for all environments
- [ ] PostgreSQL client available for migration verification
- [ ] Access to monitoring dashboards at {monitoring_url}

## Pre-deployment Checks

### 1. Health Check Current State
## 2. Check Recent Deployments
## 3. Verify Dependencies
## 4. Execute Deployment Steps
## 5. Post-Deployment Verification
## 6. Rollback if Needed
## 7. Troubleshooting Guide
## 8. Contacts and Escalation

RUNBOOK;

    private const TEMPLATE_VERSION = '1.0';

    public function generate(string $serviceName, string $environment): string
    {
        return strtr(self::RUNBOOK_TEMPLATE, [
            '{service_name}' => $serviceName,
            '{environment}' => $environment,
            '{service_description}' => 'sample service',
            '{cluster}' => 'default',
            '{monitoring_url}' => 'https://monitoring.example.com',
        ]);
    }
}
