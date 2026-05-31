<?php

declare(strict_types=1);

namespace App\Mail\Support;

use App\Domain\Ticket;
use App\Templating\TemplateEngine;
use DateTimeImmutable;
use DateTimeZone;

final class TicketAutoReplyMailer
{
    public function __construct(private TemplateEngine $templates) {}

    public function compose(Ticket $ticket): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dow = (int) $now->format('N');
        $hour = (int) $now->format('G');

        $insideHours = $dow >= 1 && $dow <= 5 && $hour >= 9 && $hour < 17;

        if ($insideHours) {
            $expectedReply = 'within 4 business hours';
            $tone = 'prompt';
        } else {
            $expectedReply = 'on the next business day (Monday-Friday, 09:00-17:00 UTC)';
            $tone = 'after_hours';
        }

        $context = [
            'first_name' => $ticket->customerFirstName,
            'ticket_id' => $ticket->id,
            'subject' => $ticket->subject,
            'expected_reply' => $expectedReply,
            'tone' => $tone,
            'business_hours' => 'Monday-Friday, 09:00-17:00 UTC',
        ];

        return [
            'to' => $ticket->customerEmail,
            'subject' => sprintf('We received your ticket #%d', $ticket->id),
            'body' => $this->templates->render('emails.support.auto_reply', $context),
        ];
    }
}
