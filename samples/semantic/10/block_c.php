<?php

declare(strict_types=1);

namespace Acme\Queue\Workers;

use Acme\Queue\Message;
use Acme\Queue\Bus\AckBus;
use Acme\Auth\Model\Session;
use Acme\Auth\Repository\SessionRepository;

final class BackgroundActionWorker
{
    public function __construct(
        private SessionRepository $sessions,
        private AckBus $ack,
    ) {
    }

    public function handle(Message $msg): void
    {
        $sessionId = (string) $msg->payload()['session_id'];
        $session = $this->sessions->find($sessionId);

        if ($session === null) {
            $this->ack->reject($msg, 'no_session');
            return;
        }

        if (!$session->isValid()) {
            $this->ack->reject($msg, 'session_invalid');
            return;
        }

        $this->performAction($msg, $session);
        $this->ack->complete($msg);
    }

    private function performAction(Message $msg, Session $session): void
    {
        // application work...
    }
}
