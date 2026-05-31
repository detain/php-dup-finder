<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PasswordResetMailTest extends TestCase
{
    use RefreshDatabase;

    public function testRendersCorrectContent(): void
    {
        $user = User::factory()->create([
            'email' => 'forgot@example.test',
            'name'  => 'Forgotten Soul',
        ]);
        $token = 'tok-' . bin2hex(random_bytes(8));

        $mailable = new PasswordResetMail($user, $token);
        $rendered = $mailable->render();
        $envelope = $mailable->envelope();

        $this->assertSame('Reset your password', $envelope->subject);
        $this->assertStringContainsString('Forgotten Soul', $rendered);
        $this->assertStringContainsString($token, $rendered);
        $this->assertStringContainsString('expires in 60 minutes', $rendered);

        $to = $envelope->to[0];
        $this->assertSame('forgot@example.test', $to->address);
    }

    public function testHasNoAttachments(): void
    {
        $user  = User::factory()->create();
        $token = 'tok-abc';

        $mailable = new PasswordResetMail($user, $token);
        $this->assertSame([], $mailable->attachments());
    }
}
