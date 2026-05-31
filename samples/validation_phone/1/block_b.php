<?php
declare(strict_types=1);

namespace Ecommerce\Support;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class SupportTicketHandler
{
    public function __construct(
        private readonly Connection $database,
        private readonly LoggerInterface $logger,
        private readonly SmsNotificationService $smsService
    ) {}

    public function createTicket(Request $request): JsonResponse
    {
        $email = $request->request->get('email', '');
        $contactPhone = $request->request->get('contact_phone', '');
        $category = $request->request->get('category', 'general');
        $subject = $request->request->get('subject', '');
        $description = $request->request->get('description', '');

        // Validate contact phone if provided
        if (!empty($contactPhone)) {
            $phoneResult = $this->parseAndValidatePhone($contactPhone);
            if (!$phoneResult['is_valid']) {
                $this->logger->debug('Support ticket rejected: invalid phone', [
                    'reason' => $phoneResult['error']
                ]);
                return $this->json(['error' => $phoneResult['error']], 400);
            }
            $contactPhone = $phoneResult['formatted'];
        }

        // Create ticket
        $ticketId = $this->database->fetchOne(
            'INSERT INTO support_tickets
             (email, contact_phone, category, subject, description, status, priority, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             RETURNING id',
            [$email, $contactPhone ?: null, $category, $subject, $description, 'open', $this->determinePriority($category)]
        );

        $this->logger->info('Support ticket created', [
            'ticket_id' => $ticketId,
            'category' => $category,
            'has_phone' => !empty($contactPhone)
        ]);

        // Send SMS notification if phone provided
        if (!empty($contactPhone)) {
            $this->smsService->send(
                $contactPhone,
                "Ticket #{$ticketId} received. We'll respond within 24 hours."
            );
        }

        return $this->json([
            'ticket_id' => $ticketId,
            'message' => 'Your support request has been submitted successfully'
        ], 201);
    }

    private function parseAndValidatePhone(string $phone): array
    {
        // Strip whitespace and special characters
        $cleaned = trim($phone);
        $digits = preg_replace('/[\s\-\(\)\.\+]/', '', $cleaned);

        // Must be digits only at this point
        if (!ctype_digit($digits)) {
            return ['is_valid' => false, 'error' => 'Phone number must contain only digits'];
        }

        // Length validation
        $len = strlen($digits);
        if ($len < 10) {
            return ['is_valid' => false, 'error' => 'Phone number must be at least 10 digits'];
        }
        if ($len > 15) {
            return ['is_valid' => false, 'error' => 'Phone number cannot exceed 15 digits'];
        }

        // Check for valid area code patterns (US/CA focus)
        if (preg_match('/^1?([2-9]\d{9})$/', $digits, $matches)) {
            $formatted = '+1' . $matches[1];
        } elseif (preg_match('/^44?([2-9]\d{9,10})$/', $digits, $matches)) {
            $formatted = '+44' . ltrim($matches[1], '0');
        } elseif (preg_match('/^61?([2-9]\d{8})$/', $digits, $matches)) {
            $formatted = '+61' . $matches[1];
        } else {
            // Generic international format
            $formatted = '+' . ltrim($digits, '+');
        }

        return ['is_valid' => true, 'formatted' => $formatted];
    }

    private function determinePriority(string $category): string
    {
        return match ($category) {
            'technical', 'billing_dispute' => 'high',
            'refund', 'cancellation' => 'medium',
            default => 'low'
        };
    }
}
