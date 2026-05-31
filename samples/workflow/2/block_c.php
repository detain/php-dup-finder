<?php
declare(strict_types=1);

namespace App\User\Onboarding;

use App\Domain\Entity\User;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Service\EmailServiceInterface;
use App\Domain\Service\ProfileServiceInterface;
use App\Domain\Service\PermissionServiceInterface;
use App\Domain\Service\AnalyticsServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class PartnerOnboardingWorkflow
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private EmailServiceInterface $emailService,
        private ProfileServiceInterface $profileService,
        private PermissionServiceInterface $permissionService,
        private AnalyticsServiceInterface $analyticsService,
        private LoggerInterface $logger,
    ) {}

    public function onboardPartner(string $userId): void
    {
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw new \RuntimeException("User not found: {$userId}");
        }

        $this->logger->info('Starting partner onboarding workflow', ['user_id' => $userId]);

        $this->createUserAccount($user);

        $this->setupUserProfile($user);

        $this->assignPartnerPermissions($user);

        $this->sendWelcomeEmail($user);

        $this->sendPartnerWelcomeKit($user);

        $this->setupAnalytics($user);

        $this->recordOnboardingComplete($user);

        $this->logger->info('Partner onboarding workflow completed', ['user_id' => $userId]);
    }

    private function createUserAccount(User $user): void
    {
        $user->setStatus('active');
        $user->setEmailVerified(false);
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setOnboardingStartedAt(new \DateTimeImmutable());
        $this->userRepository->save($user);

        $this->logStep('account_created', ['user_id' => $user->getId()->toString()]);
    }

    private function setupUserProfile(User $user): void
    {
        $this->profileService->initialize([
            'user_id' => $user->getId()->toString(),
            'display_name' => $user->getProfile()->getDisplayName() ?? $user->getEmail(),
            'timezone' => 'UTC',
            'language' => 'en',
            'company' => $user->getPartnerCompany(),
        ]);

        $user->getProfile()->setOnboardingCompleted(true);
        $this->userRepository->save($user);

        $this->logStep('profile_setup_completed', ['user_id' => $user->getId()->toString()]);
    }

    private function assignPartnerPermissions(User $user): void
    {
        $this->permissionService->assignRole($user, 'partner');

        $this->permissionService->grantPermissions($user, [
            'profile:read',
            'profile:update',
            'referrals:read',
            'referrals:create',
            'commissions:read',
            'marketing:read',
            'marketing:create',
        ]);

        $this->logStep('permissions_assigned', ['user_id' => $user->getId()->toString()]);
    }

    private function sendWelcomeEmail(User $user): void
    {
        $this->emailService->sendTemplate(
            $user->getEmail(),
            'welcome_partner',
            [
                'user_name' => $user->getProfile()->getFirstName(),
                'partner_portal_link' => 'https://example.com/partners',
                'verification_link' => $this->generateVerificationLink($user),
            ]
        );

        $this->logStep('welcome_email_sent', [
            'user_id' => $user->getId()->toString(),
            'email' => $user->getEmail(),
        ]);
    }

    private function sendPartnerWelcomeKit(User $user): void
    {
        $this->emailService->sendTemplate(
            $user->getEmail(),
            'partner_welcome_kit',
            [
                'user_name' => $user->getProfile()->getFirstName(),
                'company_name' => $user->getPartnerCompany(),
                'resources_link' => 'https://example.com/partners/resources',
                'support_link' => 'https://example.com/partners/support',
            ]
        );

        $this->logStep('partner_welcome_kit_sent', ['user_id' => $user->getId()->toString()]);
    }

    private function setupAnalytics(User $user): void
    {
        $this->analyticsService->identifyUser($user->getId()->toString(), [
            'email' => $user->getEmail(),
            'created_at' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'user_type' => 'partner',
            'company' => $user->getPartnerCompany(),
            'onboarding_source' => $user->getOnboardingSource(),
        ]);

        $this->analyticsService->trackEvent($user->getId()->toString(), 'onboarding_completed', [
            'workflow' => 'partner_onboarding',
            'duration_seconds' => time() - $user->getOnboardingStartedAt()->getTimestamp(),
        ]);

        $this->logStep('analytics_configured', ['user_id' => $user->getId()->toString()]);
    }

    private function recordOnboardingComplete(User $user): void
    {
        $user->setOnboardingCompletedAt(new \DateTimeImmutable());
        $user->setOnboardingStep(100);
        $this->userRepository->save($user);

        $this->logStep('onboarding_completed', [
            'user_id' => $user->getId()->toString(),
            'duration_seconds' => time() - $user->getOnboardingStartedAt()->getTimestamp(),
        ]);
    }

    private function generateVerificationLink(User $user): string
    {
        return "https://example.com/verify-email?token=" . bin2hex(random_bytes(32));
    }

    private function logStep(string $step, array $context): void
    {
        $this->logger->debug("Onboarding step: {$step}", $context);
    }
}
