<?php

declare(strict_types=1);

namespace Tests\Integration\Events;

use App\Events\PaymentRefunded;
use App\Listeners\SendRefundReceipt;
use App\Listeners\UpdateLedger;
use App\Mail\RefundReceipt;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PaymentRefundedListenerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([\App\Events\LedgerUpdated::class]);
        Mail::fake();
        Queue::fake();
    }

    public function testListenersFireOnPaymentRefunded(): void
    {
        $user    = User::factory()->create(['email' => 'payer@example.test']);
        $payment = Payment::factory()->create(['user_id' => $user->id, 'amount' => 2599]);

        $event = new PaymentRefunded($payment);
        event($event);

        Mail::assertSent(RefundReceipt::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        Queue::assertPushed(UpdateLedger::class);

        $this->assertDatabaseHas('payments', [
            'id'     => $payment->id,
            'status' => 'refunded',
        ]);
    }
}
