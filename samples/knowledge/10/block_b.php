<?php

declare(strict_types=1);

namespace App\Privacy\Gdpr;

use App\Repositories\CustomerRepository;
use App\Repositories\OrderRepository;
use App\Repositories\TicketRepository;
use DateTimeImmutable;

final class SubjectAccessExporter
{
    public function __construct(
        private CustomerRepository $customers,
        private OrderRepository $orders,
        private TicketRepository $tickets,
    ) {}

    public function build(int $customerId): array
    {
        $customer = $this->customers->findDecrypted($customerId);

        // GDPR requires every PII field stored about the subject in the export.
        $pii = [
            'ssn'           => $customer->ssn,
            'date_of_birth' => $customer->dateOfBirth,
            'phone'         => $customer->phone,
            'email'         => $customer->email,
            'full_name'     => $customer->fullName,
            'address_line'  => $customer->addressLine,
        ];

        $payload = [
            'subject_id' => $customerId,
            'generated_at' => (new DateTimeImmutable())->format('c'),
            'personal_information' => $pii,
            'orders' => array_map(
                fn ($o) => [
                    'id' => $o->id,
                    'total_cents' => $o->totalCents,
                    'placed_at' => $o->placedAt->format('c'),
                ],
                $this->orders->forCustomer($customerId),
            ),
            'support_tickets' => array_map(
                fn ($t) => [
                    'id' => $t->id,
                    'subject' => $t->subject,
                    'opened_at' => $t->openedAt->format('c'),
                ],
                $this->tickets->forCustomer($customerId),
            ),
        ];

        return $payload;
    }
}
