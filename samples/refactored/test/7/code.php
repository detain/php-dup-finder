<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Tests\TestCase;

abstract class AbstractMailableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param list<string> $bodyMustContain
     */
    protected function assertMailableRenders(
        Mailable $mailable,
        string $expectedSubject,
        string $expectedRecipient,
        array $bodyMustContain
    ): void {
        $rendered = $mailable->render();
        $envelope = $mailable->envelope();

        $this->assertSame($expectedSubject, $envelope->subject);
        $this->assertSame($expectedRecipient, $envelope->to[0]->address);

        foreach ($bodyMustContain as $fragment) {
            $this->assertStringContainsString($fragment, $rendered);
        }
    }

    protected function assertHasAttachment(Mailable $mailable, string $name): void
    {
        $attachments = $mailable->attachments();
        $names = array_map(static fn ($a) => $a->as, $attachments);
        $this->assertContains($name, $names);
    }
}
