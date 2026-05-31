<?php

declare(strict_types=1);

namespace App\Subscriptions;

use App\Entity\Subscriber;
use App\Repository\SubscriberRepository;
use App\Service\PasswordHasher;
use App\Service\TokenGenerator;
use App\Event\SubscriptionStartedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class SubscriberOnboardingService
{
    public function __construct(
        private readonly SubscriberRepository $subscriberRepository,
        private readonly PasswordHasher $passwordHasher,
        private readonly TokenGenerator $tokenGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function onboardSubscriber(array $onboardingData): Subscriber
    {
        $emailAddress = $this->sanitizeEmail($onboardingData['email_address'] ?? '');
        $initialPassword = $onboardingData['password'] ?? '';
        $displayName = $this->sanitizeDisplayName($onboardingData['display_name'] ?? '');
        $screenName = $this->generateScreenName($displayName, $emailAddress);

        if ($this->subscriberRepository->existsByEmailAddress($emailAddress)) {
            $this->logger->warning('Onboarding attempt with existing email', [
                'email_address' => $this->maskEmail($emailAddress),
            ]);
            throw new \InvalidArgumentException('Email address is already in use');
        }

        if (strlen($initialPassword) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters long');
        }

        $hashedPassword = $this->passwordHasher->hash($initialPassword);
        $confirmationToken = $this->tokenGenerator->generateSecureToken();

        $subscriber = new Subscriber();
        $subscriber->setEmailAddress($emailAddress);
        $subscriber->setPassword($hashedPassword);
        $subscriber->setDisplayName($displayName);
        $subscriber->setScreenName($screenName);
        $subscriber->setConfirmationToken($confirmationToken);
        $subscriber->setState('awaiting_confirmation');
        $subscriber->setCreatedAt(new \DateTimeImmutable());

        $this->subscriberRepository->save($subscriber);

        $this->eventDispatcher->dispatch(
            new SubscriptionStartedEvent($subscriber),
            SubscriptionStartedEvent::NAME
        );

        $this->logger->info('New subscriber onboarded successfully', [
            'subscriber_id' => $subscriber->getId(),
            'email_address' => $this->maskEmail($emailAddress),
        ]);

        return $subscriber;
    }

    private function sanitizeEmail(string $emailAddress): string
    {
        $emailAddress = trim(strtolower($emailAddress));
        if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address format');
        }
        return $emailAddress;
    }

    private function sanitizeDisplayName(string $displayName): string
    {
        return trim(preg_replace('/\s+/', ' ', $displayName));
    }

    private function generateScreenName(string $displayName, string $emailAddress): string
    {
        $baseScreenName = strtolower(explode('@', $emailAddress)[0]);
        $namePart = strtolower(str_replace(' ', '', $displayName));
        $screenName = $namePart ?: $baseScreenName;

        $counter = 1;
        $candidate = $screenName;
        while ($this->subscriberRepository->existsByScreenName($candidate)) {
            $candidate = $screenName . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function maskEmail(string $emailAddress): string
    {
        $parts = explode('@', $emailAddress);
        $local = $parts[0];
        $domain = $parts[1] ?? '';
        $maskedLocal = substr($local, 0, 2) . '***';
        return $maskedLocal . '@' . $domain;
    }
}
