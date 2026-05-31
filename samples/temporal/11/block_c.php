<?php
declare(strict_types=1);

namespace Shopify\Onboarding\Workflow;

use Shopify\Onboarding\Entity\User;
use Shopify\Onboarding\Entity\EmailVerification;
use Shopify\Onboarding\Entity\StoreProfile;
use Shopify\Onboarding\Repository\UserRepository;
use Shopify\Onboarding\Repository\EmailTokenRepository;
use Shopify\Onboarding\Service\EmailService;
use Shopify\Onboarding\Service\ProfileInitializationService;
use Shopify\Core\Security\TokenGenerator;
use Shopify\Core\Logging\ActivityLogger;

final class UserOnboardingOrchestrator
{
    private UserRepository $userRepository;
    private EmailTokenRepository $tokenRepository;
    private EmailService $emailService;
    private ProfileInitializationService $profileService;
    private TokenGenerator $tokenGenerator;
    private ActivityLogger $activityLogger;

    public function __construct(
        UserRepository $userRepository,
        EmailTokenRepository $tokenRepository,
        EmailService $emailService,
        ProfileInitializationService $profileService,
        TokenGenerator $tokenGenerator,
        ActivityLogger $activityLogger
    ) {
        $this->userRepository = $userRepository;
        $this->tokenRepository = $tokenRepository;
        $this->emailService = $emailService;
        $this->profileService = $profileService;
        $this->tokenGenerator = $tokenGenerator;
        $this->activityLogger = $activityLogger;
    }

    public function onboardUser(array $userData): OnboardingResult
    {
        $this->activityLogger->log('onboarding_started', [
            'email' => $userData['email'] ?? 'unknown'
        ]);

        $user = User::create([
            'email' => $userData['email'],
            'password_hash' => password_hash($userData['password'], PASSWORD_ARGON2ID),
            'first_name' => $userData['first_name'],
            'last_name' => $userData['last_name'],
            'status' => 'pending_verification',
            'created_at' => new \DateTimeImmutable()
        ]);

        $savedUser = $this->userRepository->save($user);

        $verificationToken = $this->tokenGenerator->generateSecureToken(32);
        $emailVerification = EmailVerification::create([
            'user_id' => $savedUser->getId(),
            'token' => hash('sha256', $verificationToken),
            'token_type' => 'email_verification',
            'expires_at' => (new \DateTimeImmutable())->modify('+24 hours'),
            'created_at' => new \DateTimeImmutable()
        ]);
        $this->tokenRepository->save($emailVerification);

        try {
            $this->emailService->sendTemplate(
                $savedUser->getEmail(),
                'welcome_verification',
                [
                    'first_name' => $savedUser->getFirstName(),
                    'verification_url' => $this->buildVerificationUrl($verificationToken),
                    'expires_hours' => 24
                ]
            );

            $this->activityLogger->log('verification_email_sent', [
                'user_id' => $savedUser->getId()
            ]);

        } catch (\Throwable $emailError) {
            $this->activityLogger->log('verification_email_failed', [
                'user_id' => $savedUser->getId(),
                'error' => $emailError->getMessage()
            ]);
        }

        $storeProfile = $this->profileService->initializeDefaultProfile(
            $savedUser->getId(),
            $userData['store_name'] ?? 'My Store'
        );

        $this->userRepository->updateSetupProgress(
            $savedUser->getId(),
            'profile_created'
        );

        $this->activityLogger->log('onboarding_completed', [
            'user_id' => $savedUser->getId(),
            'store_id' => $storeProfile->getId()
        ]);

        return new OnboardingResult([
            'user_id' => $savedUser->getId(),
            'store_id' => $storeProfile->getId(),
            'requires_email_verification' => true
        ]);
    }

    private function buildVerificationUrl(string $token): string
    {
        return "https://api.shopify.example.com/verify?token={$token}";
    }
}
