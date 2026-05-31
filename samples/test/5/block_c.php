<?php

declare(strict_types=1);

namespace Tests\Integration\Events;

use App\Events\UserRegistered;
use App\Listeners\SendWelcomeEmail;
use App\Listeners\ProvisionTenant;
use App\Mail\WelcomeMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class UserRegisteredListenerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([\App\Events\TenantProvisioned::class]);
        Mail::fake();
        Queue::fake();
    }

    public function testListenersFireOnUserRegistered(): void
    {
        $user = User::factory()->create(['email' => 'new@example.test', 'tenant' => null]);

        $event = new UserRegistered($user);
        event($event);

        Mail::assertSent(WelcomeMessage::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        Queue::assertPushed(ProvisionTenant::class);

        $this->assertDatabaseHas('users', [
            'id'     => $user->id,
            'status' => 'active',
        ]);
    }
}
