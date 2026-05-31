<?php

declare(strict_types=1);

namespace App\CRM;

use App\Entity\Contact;
use App\Repository\ContactRepository;
use App\Service\PasswordHasher;
use App\Service\TokenGenerator;
use App\Event\LeadCreatedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class LeadCaptureService
{
    public function __construct(
        private readonly ContactRepository $contactRepository,
        private readonly PasswordHasher $passwordHasher,
        private readonly TokenGenerator $tokenGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function captureLead(array $leadData): Contact
    {
        $contact = $this->sanitizeContact($leadData['contact'] ?? '');
        $secretCode = $leadData['code'] ?? '';
        $fullName = $this->sanitizeFullName($leadData['full_name'] ?? '');
        $alias = $this->generateAlias($fullName, $contact);

        if ($this->contactRepository->existsByContact($contact)) {
            $this->logger->warning('Lead capture attempt with existing contact', [
                'contact' => $this->maskContact($contact),
            ]);
            throw new \InvalidArgumentException('Contact information is already registered');
        }

        if (strlen($secretCode) < 6) {
            throw new \InvalidArgumentException('Security code must be at least 6 characters long');
        }

        $hashedCode = $this->passwordHasher->hash($secretCode);
        $activationToken = $this->tokenGenerator->generateSecureToken();

        $lead = new Contact();
        $lead->setContact($contact);
        $lead->setCode($hashedCode);
        $lead->setFullName($fullName);
        $lead->setAlias($alias);
        $lead->setActivationToken($activationToken);
        $lead->setStatus('pending_activation');
        $lead->setCreatedAt(new \DateTimeImmutable());

        $this->contactRepository->save($lead);

        $this->eventDispatcher->dispatch(
            new LeadCreatedEvent($lead),
            LeadCreatedEvent::NAME
        );

        $this->logger->info('New lead captured successfully', [
            'lead_id' => $lead->getId(),
            'contact' => $this->maskContact($contact),
        ]);

        return $lead;
    }

    private function sanitizeContact(string $contact): string
    {
        $contact = trim(strtolower($contact));
        if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid contact format');
        }
        return $contact;
    }

    private function sanitizeFullName(string $fullName): string
    {
        return trim(preg_replace('/\s+/', ' ', $fullName));
    }

    private function generateAlias(string $fullName, string $contact): string
    {
        $baseAlias = strtolower(explode('@', $contact)[0]);
        $namePart = strtolower(str_replace(' ', '', $fullName));
        $alias = $namePart ?: $baseAlias;

        $counter = 1;
        $candidate = $alias;
        while ($this->contactRepository->existsByAlias($candidate)) {
            $candidate = $alias . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function maskContact(string $contact): string
    {
        $parts = explode('@', $contact);
        $local = $parts[0];
        $domain = $parts[1] ?? '';
        $maskedLocal = substr($local, 0, 2) . '***';
        return $maskedLocal . '@' . $domain;
    }
}
