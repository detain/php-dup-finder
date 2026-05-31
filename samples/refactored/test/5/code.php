<?php

declare(strict_types=1);

namespace Tests\Integration\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

abstract class AbstractListenerTestCase extends TestCase
{
    use RefreshDatabase;

    /** @return list<class-string> */
    abstract protected function fakeEvents(): array;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake($this->fakeEvents());
        Mail::fake();
        Queue::fake();
    }

    /**
     * @param class-string $mailClass
     * @param class-string $jobClass
     */
    protected function assertNotificationDispatched(
        string $mailClass,
        string $jobClass,
        string $recipientEmail
    ): void {
        Mail::assertSent($mailClass, fn ($mail) => $mail->hasTo($recipientEmail));
        Queue::assertPushed($jobClass);
    }

    protected function assertRecordStatus(string $table, int $id, string $status): void
    {
        $this->assertDatabaseHas($table, ['id' => $id, 'status' => $status]);
    }
}
