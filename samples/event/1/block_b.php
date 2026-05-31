<?php
declare(strict_types=1);

namespace App\Domain\User\EventHandler;

use App\Entity\User;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\EmailService;
use App\Service\PreferencesService;
use App\Service\AnalyticsService;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class UserRegisteredEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly EmailService $emailService,
        private readonly PreferencesService $preferencesService,
        private readonly AnalyticsService $analyticsService,
        private readonly AuditService $auditService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(User $user): void
    {
        $this->logger->info('Processing user registered event', [
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'registration_method' => $user->getRegistrationMethod(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->createDefaultPreferences($user);
            $this->sendWelcomeEmail($user);
            $this->assignToCohort($user);
            $this->triggerOnboardingFlow($user);
            $this->recordAnalyticsEvent($user);
            $this->createAuditLogEntry($user);

            $this->entityManager->commit();

            $this->logger->info('User registered event processed successfully', [
                'user_id' => $user->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process user registered event', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function createDefaultPreferences(User $user): void
    {
        $defaultPreferences = $this->preferencesService->getSystemDefaults();

        foreach ($defaultPreferences as $key => $value) {
            $preference = new \App\Entity\UserPreference();
            $preference->setUser($user);
            $preference->setPreferenceKey($key);
            $preference->setPreferenceValue($value);
            $preference->setIsSystem(true);
            $preference->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($preference);

            $this->logger->debug('Created default preference', [
                'user_id' => $user->getId(),
                'key' => $key,
                'value' => $value,
            ]);
        }
    }

    private function sendWelcomeEmail(User $user): void
    {
        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'welcome_email']);

        if ($template === null) {
            $this->logger->warning('Welcome email template not found');
            return;
        }

        $variables = [
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'email' => $user->getEmail(),
            'activation_link' => $this->generateActivationLink($user),
            'support_email' => $this->getSupportEmail(),
        ];

        $this->queueService->publish('email.outbound', [
            'template_id' => $template->getId(),
            'recipient' => $user->getEmail(),
            'variables' => $variables,
            'priority' => 'high',
        ]);

        $this->logger->debug('Queued welcome email', [
            'user_id' => $user->getId(),
            'template' => 'welcome_email',
        ]);
    }

    private function assignToCohort(User $user): void
    {
        $cohortRules = $this->entityManager
            ->getRepository(\App\Entity\CohortRule::class)
            ->findAllActive();

        $assignedCohort = null;
        foreach ($cohortRules as $rule) {
            if ($this->evaluateCohortRule($rule, $user)) {
                $assignedCohort = $rule->getCohort();
                break;
            }
        }

        if ($assignedCohort !== null) {
            $membership = new \App\Entity\CohortMembership();
            $membership->setUser($user);
            $membership->setCohort($assignedCohort);
            $membership->setAssignedAt(new \DateTimeImmutable());
            $membership->setSource('registration_rule');

            $this->entityManager->persist($membership);

            $this->logger->info('Assigned user to cohort', [
                'user_id' => $user->getId(),
                'cohort_id' => $assignedCohort->getId(),
                'cohort_name' => $assignedCohort->getName(),
            ]);
        }
    }

    private function triggerOnboardingFlow(User $user): void
    {
        $flowSteps = $this->entityManager
            ->getRepository(\App\Entity\OnboardingFlow::class)
            ->findBy(['isActive' => true, 'trigger' => 'registration']);

        foreach ($flowSteps as $step) {
            $task = new \App\Entity\OnboardingTask();
            $task->setUser($user);
            $task->setFlowStep($step);
            $task->setStatus('pending');
            $task->setDueAt(
                (new \DateTimeImmutable())->modify("+{$step->getDelayDays()} days")
            );
            $task->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($task);

            $this->queueService->publish('onboarding.task', [
                'task_id' => $task->getId(),
                'user_id' => $user->getId(),
                'step_type' => $step->getType(),
                'step_config' => $step->getConfig(),
            ]);

            $this->logger->debug('Created onboarding task', [
                'user_id' => $user->getId(),
                'step_id' => $step->getId(),
            ]);
        }
    }

    private function recordAnalyticsEvent(User $user): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('user_registered');
        $analyticsEvent->setCustomerId($user->getId());
        $analyticsEvent->setPayload([
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'registration_method' => $user->getRegistrationMethod(),
            'referral_code' => $user->getReferralCode(),
            'source' => $user->getRegistrationSource(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);
        $this->analyticsService->enqueueBatchFlush();

        $this->logger->debug('Recorded analytics event', [
            'user_id' => $user->getId(),
            'event' => 'user_registered',
        ]);
    }

    private function createAuditLogEntry(User $user): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('USER_REGISTERED');
        $auditEntry->setEntityType('user');
        $auditEntry->setEntityId($user->getId());
        $auditEntry->setUserId($user->getId());
        $auditEntry->setMetadata([
            'email' => $user->getEmail(),
            'registration_method' => $user->getRegistrationMethod(),
            'ip_address' => $user->getRegistrationIp(),
            'user_agent' => $user->getRegistrationUserAgent(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'user_id' => $user->getId(),
            'action' => 'USER_REGISTERED',
        ]);
    }

    private function generateActivationLink(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $expiry = (new \DateTimeImmutable())->modify('+24 hours');

        $activation = new \App\Entity\ActivationToken();
        $activation->setUser($user);
        $activation->setToken($token);
        $activation->setExpiresAt($expiry);
        $activation->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($activation);

        return sprintf('/activate?token=%s&user=%d', $token, $user->getId());
    }

    private function getSupportEmail(): string
    {
        return $this->entityManager
            ->getRepository(\App\Entity\SystemSetting::class)
            ->findOneBy(['key' => 'support_email'])
            ?->getValue() ?? 'support@example.com';
    }

    private function evaluateCohortRule(\App\Entity\CohortRule $rule, User $user): bool
    {
        $conditions = $rule->getConditions();
        foreach ($conditions as $field => $operator) {
            $value = null;
            $userValue = match ($field) {
                'registration_method' => $user->getRegistrationMethod(),
                'country' => $user->getCountry(),
                'age_group' => $user->getAgeGroup(),
                default => null,
            };

            if (!$this->compareValues($userValue, $operator, $value)) {
                return false;
            }
        }
        return true;
    }

    private function compareValues(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'eq' => $actual === $expected,
            'neq' => $actual !== $expected,
            'in' => in_array($actual, (array) $expected, true),
            default => false,
        };
    }
}
