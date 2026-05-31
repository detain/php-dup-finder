<?php

declare(strict_types=1);

namespace Tests\Integration\Events;

use App\Events\OrderPlaced;
use App\Listeners\SendOrderConfirmationEmail;
use App\Listeners\NotifyWarehouse;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class OrderPlacedListenerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([\App\Events\OrderShipped::class]);
        Mail::fake();
        Queue::fake();
    }

    public function testListenersFireOnOrderPlaced(): void
    {
        $user  = User::factory()->create(['email' => 'buyer@example.test']);
        $order = Order::factory()->create(['user_id' => $user->id, 'total' => 4999]);

        $event = new OrderPlaced($order);
        event($event);

        Mail::assertSent(OrderConfirmation::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        Queue::assertPushed(NotifyWarehouse::class);

        $this->assertDatabaseHas('orders', [
            'id'     => $order->id,
            'status' => 'placed',
        ]);
    }
}
