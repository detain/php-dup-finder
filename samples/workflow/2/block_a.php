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

final readonly class CustomerOnboardingWorkflow
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private EmailServiceInterface $emailService,
        private ProfileServiceInterface $profileService,
        private PermissionServiceInterface $permissionService,
        private AnalyticsServiceInterface $analyticsService,
        private LoggerInterface $logger,
    ) {}

    public function onboardCustomer(string $userId): void
    {
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw new \RuntimeException("User not found: {$userId}");
        }

        $this->logger->info('Starting customer onboarding workflow', ['user_id' => $userId]);

        $this->createUserAccount($user);

        $this->setupUserProfile($user);

        $this->assignDefaultPermissions($user);

        $this->sendWelcomeEmail($user);

        $this->setupAnalytics($user);

        $this->recordOnboardingComplete($user);

        $this->logger->info('Customer onboarding workflow completed', ['user_id' => $userId]);
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
        ]);

        $user->getProfile()->setOnboardingCompleted(true);
        $this->userRepository->save($user);

        $this->logStep('profile_setup_completed', ['user_id' => $user->getId()->toString()]);
    }

    private function assignDefaultPermissions(User $user): void
    {
        $this->permissionService->assignRole($user, 'customer');

        $this->permissionService->grantPermissions($user, [
            'profile:read',
            'profile:update',
            'orders:create',
            'orders:read',
            'orders:cancel_own',
        ]);

        $this->logStep('permissions_assigned', ['user_id' => $user->getId()->toString()]);
    }

    private function sendWelcomeEmail(User $user): void
    {
        $this->emailService->sendTemplate(
            $user->getEmail(),
            'welcome_customer',
            [
                'user_name' => $user->getProfile()->getFirstName(),
                'verification_link' => $this->generateVerificationLink($user),
            ]
        );

        $this->logStep('welcome_email_sent', [
            'user_id' => $user->getId()->toString(),
            'email' => $user->getEmail(),
        ]);
    }

    private function setupAnalytics(User $user): void
    {
        $this->analyticsService->identifyUser($user->getId()->toString(), [
            'email' => $user->getEmail(),
            'created_at' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'user_type' => 'customer',
            'onboarding_source' => $user->getOnboardingSource(),
        ]);

        $this->analyticsService->trackEvent($user->getId()->toString(), 'onboarding_completed', [
            'workflow' => 'customer_onboarding',
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
