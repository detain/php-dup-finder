<?php
declare(strict_types=1);

namespace Ecommerce\Support;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ContactFormHandler
{
    public function __construct(
        private readonly Connection $database,
        private readonly LoggerInterface $logger,
        private readonly SpamDetector $spamDetector
    ) {}

    public function handleSubmit(Request $request): Response
    {
        $email = $request->request->get('email', '');
        $subject = $request->request->get('subject', '');
        $message = $request->getContent();
        $category = $request->request->get('category', 'general');

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->debug('Contact form rejected: invalid email format', [
                'ip' => $request->getClientIp(),
                'subject' => $subject
            ]);
            return new JsonResponse([
                'success' => false,
                'message' => 'A valid email address is required to submit a support request'
            ], 400);
        }

        // Block disposable email domains
        $disposableDomains = ['tempmail.com', 'throwaway.email', 'guerrillamail.com',
            'mailinator.com', '10minutemail.com', 'fakeinbox.com',
            'trashmail.com', 'dispostable.com', 'maildrop.cc'];
        $emailDomain = strtolower(substr(strrchr($email, '@'), 1));
        if (in_array($emailDomain, $disposableDomains, true)) {
            $this->logger->warning('Contact form blocked: disposable email domain', [
                'domain' => $emailDomain,
                'ip' => $request->getClientIp()
            ]);
            return new JsonResponse([
                'success' => false,
                'message' => 'Disposable email addresses are not permitted for support submissions'
            ], 403);
        }

        // Ensure email length is within protocol limits
        if (strlen($email) > 254) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Email address is too long'
            ], 400);
        }

        // Check for spam content
        if ($this->spamDetector->isSpam($subject . ' ' . $message)) {
            $this->logger->info('Contact form flagged as spam', [
                'email' => substr($email, 0, 3) . '***',
                'ip' => $request->getClientIp()
            ]);
            return new JsonResponse([
                'success' => false,
                'message' => 'Your request could not be submitted. Please try again later.'
            ], 422);
        }

        // Store the support ticket
        $ticketId = $this->database->fetchOne(
            'INSERT INTO support_tickets (email, subject, message, category, status, created_at)
             VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id',
            [strtolower($email), $subject, $message, $category, 'open']
        );

        $this->logger->info('Support ticket created', [
            'ticket_id' => $ticketId,
            'category' => $category,
            'email' => substr($email, 0, 3) . '***'
        ]);

        return new JsonResponse([
            'success' => true,
            'ticket_id' => $ticketId,
            'message' => 'Thank you for contacting us. A support representative will respond within 24 hours.'
        ], 201);
    }
}
