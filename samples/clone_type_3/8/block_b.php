<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Event\UserRegisteredEvent;
use App\Event\UserEmailVerifiedEvent;
use App\Event\UserPasswordResetEvent;
use App\Event\UserSuspendedEvent;
use App\Service\NotificationService;
use App\Service\AuthService;
use App\Service\AnalyticsService;
use Psr\Log\LoggerInterface;

final class UserEventSubscriber
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly AuthService $authService,
        private readonly AnalyticsService $analyticsService,
        private readonly LoggerInterface $logger,
    ) {}

    public function onUserRegistered(UserRegisteredEvent $event): void
    {
        $user = $event->getUser();

        $this->notificationService->sendWelcomeEmail($user);
        $this->authService->createUserSession($user);

        $this->analyticsService->trackEvent('user_registered', [
            'user_id' => $user->getId(),
            'registration_method' => $event->getRegistrationMethod(),
        ]);

        $this->logger->info('User registered event processed', [
            'user_id' => $user->getId(),
        ]);
    }

    public function onUserEmailVerified(UserEmailVerifiedEvent $event): void
    {
        $user = $event->getUser();

        $this->notificationService->sendEmailVerifiedNotification($user);
        $this->analyticsService->trackEvent('email_verified', [
            'user_id' => $user->getId(),
            'verification_date' => $event->getVerificationDate()->format('Y-m-d'),
        ]);

        $this->logger->info('Email verified event processed', [
            'user_id' => $user->getId(),
        ]);
    }

    public function onUserPasswordReset(UserPasswordResetEvent $event): void
    {
        $user = $event->getUser();
        $resetToken = $event->getResetToken();

        $this->notificationService->sendPasswordResetConfirmation($user, $resetToken);

        $this->analyticsService->trackEvent('password_reset', [
            'user_id' => $user->getId(),
            'ip_address' => $event->getIpAddress(),
        ]);

        $this->logger->info('Password reset event processed', [
            'user_id' => $user->getId(),
        ]);
    }

    public function onUserSuspended(UserSuspendedEvent $event): void
    {
        $user = $event->getUser();
        $reason = $event->getReason();

        $this->authService->invalidateUserSessions($user);
        $this->notificationService->sendSuspensionNotification($user, $reason);

        $this->analyticsService->trackEvent('user_suspended', [
            'user_id' => $user->getId(),
            'reason' => $reason,
        ]);

        $this->logger->info('User suspended event processed', [
            'user_id' => $user->getId(),
            'reason' => $reason,
        ]);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserRegisteredEvent::class => 'onUserRegistered',
            UserEmailVerifiedEvent::class => 'onUserEmailVerified',
            UserPasswordResetEvent::class => 'onUserPasswordReset',
            UserSuspendedEvent::class => 'onUserSuspended',
        ];
    }
}
