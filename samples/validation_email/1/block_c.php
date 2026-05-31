<?php
declare(strict_types=1);

namespace Ecommerce\Marketing;

use Doctrine\ORM\EntityRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class NewsletterSubscriptionService
{
    public function __construct(
        private readonly EntityRepository $subscriberRepo,
        private readonly LoggerInterface $logger,
        private readonly MailchimpClient $mailchimp
    ) {}

    public function subscribe(string $email, array $preferences = []): SubscriptionResult
    {
        // Validate email address format per RFC 5321
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->error('Newsletter subscription failed: invalid email format', [
                'email' => substr($email, 0, 3) . '***',
                'preferences' => $preferences
            ]);
            return SubscriptionResult::failure('Please provide a valid email address');
        }

        // Reject known disposable email providers
        $blockedDomains = [
            'tempmail.com' => true, 'throwaway.email' => true,
            'guerrillamail.com' => true, 'mailinator.com' => true,
            '10minutemail.com' => true, 'fakeinbox.com' => true,
            'trashmail.com' => true, 'dispostable.com' => true,
            'maildrop.cc' => true
        ];
        $emailDomain = strtolower(substr(strrchr($email, '@'), 1));
        if (isset($blockedDomains[$emailDomain])) {
            $this->logger->notice('Newsletter subscription blocked: disposable domain', [
                'domain' => $emailDomain
            ]);
            return SubscriptionResult::failure('Newsletter subscriptions require a permanent email address');
        }

        // Enforce maximum email address length per RFC 5321
        if (strlen($email) > 254) {
            $this->logger->warning('Newsletter subscription rejected: email too long', [
                'length' => strlen($email)
            ]);
            return SubscriptionResult::failure('Email address exceeds allowed length');
        }

        // Check if already subscribed
        $existing = $this->subscriberRepo->findOneBy(['email' => strtolower($email)]);
        if ($existing !== null) {
            if ($existing->isUnsubscribed()) {
                // Re-subscribe previous subscriber
                $existing->setStatus('active');
                $existing->setPreferences($preferences);
                $existing->setResubscribedAt(new \DateTimeImmutable());
                $this->mailchimp->subscribe($email, $preferences);

                $this->logger->info('Newsletter re-subscription successful', [
                    'email' => substr($email, 0, 3) . '***'
                ]);
                return SubscriptionResult::success('Welcome back! Your subscription has been reactivated.');
            }
            return SubscriptionResult::failure('This email is already subscribed to our newsletter');
        }

        // Create new subscriber
        $subscriber = new NewsletterSubscriber();
        $subscriber->setEmail(strtolower($email));
        $subscriber->setPreferences($preferences);
        $subscriber->setStatus('active');
        $subscriber->setSubscribedAt(new \DateTimeImmutable());
        $subscriber->setConfirmationToken(bin2hex(random_bytes(16)));

        $this->subscriberRepo->getEntityManager()->persist($subscriber);
        $this->subscriberRepo->getEntityManager()->flush();

        // Sync to Mailchimp
        $this->mailchimp->subscribe($email, $preferences);

        $this->logger->info('Newsletter subscription created', [
            'email' => substr($email, 0, 3) . '***',
            'preferences' => array_keys($preferences)
        ]);

        return SubscriptionResult::success('Thank you for subscribing to our newsletter!');
    }
}
