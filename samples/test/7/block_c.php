<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\InvoiceIssuedMail;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InvoiceIssuedMailTest extends TestCase
{
    use RefreshDatabase;

    public function testRendersCorrectContent(): void
    {
        $user    = User::factory()->create(['email' => 'billed@example.test', 'name' => 'Billed Co']);
        $invoice = Invoice::factory()->create([
            'user_id'      => $user->id,
            'amount_cents' => 25000,
            'number'       => 'INV-7700',
        ]);

        $mailable = new InvoiceIssuedMail($invoice);
        $rendered = $mailable->render();
        $envelope = $mailable->envelope();

        $this->assertSame('Invoice INV-7700 issued', $envelope->subject);
        $this->assertStringContainsString('Billed Co', $rendered);
        $this->assertStringContainsString('INV-7700', $rendered);
        $this->assertStringContainsString('$250.00', $rendered);

        $to = $envelope->to[0];
        $this->assertSame('billed@example.test', $to->address);
    }

    public function testAttachesInvoicePdf(): void
    {
        $user    = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id]);

        $mailable = new InvoiceIssuedMail($invoice);
        $attachments = $mailable->attachments();

        $this->assertCount(1, $attachments);
        $this->assertSame('invoice.pdf', $attachments[0]->as);
    }
}
