<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function testRendersCorrectContent(): void
    {
        $user  = User::factory()->create(['email' => 'buyer@example.test', 'name' => 'Buyer']);
        $order = Order::factory()->create([
            'user_id'     => $user->id,
            'total_cents' => 4999,
            'number'      => 'ORD-1001',
        ]);

        $mailable = new OrderConfirmation($order);
        $rendered = $mailable->render();
        $envelope = $mailable->envelope();

        $this->assertSame('Order ORD-1001 confirmed', $envelope->subject);
        $this->assertStringContainsString('Buyer', $rendered);
        $this->assertStringContainsString('ORD-1001', $rendered);
        $this->assertStringContainsString('$49.99', $rendered);

        $to = $envelope->to[0];
        $this->assertSame('buyer@example.test', $to->address);
    }

    public function testAttachesInvoicePdf(): void
    {
        $user  = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $mailable = new OrderConfirmation($order);
        $attachments = $mailable->attachments();

        $this->assertCount(1, $attachments);
        $this->assertSame('invoice.pdf', $attachments[0]->as);
    }
}
