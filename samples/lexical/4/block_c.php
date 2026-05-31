<?php
declare(strict_types=1);

namespace Acme\Mail\Composer;

use Acme\Mail\Message;
use Acme\Mail\Internal\MessageBuilder;

final class TransactionalEmailFactory
{
    public function __construct(
        private readonly string $defaultFrom,
    ) {
    }

    public function welcomeEmail(string $to, string $subject, string $body, string $locale): Message
    {
        $builder = new MessageBuilder();
        // identical lexeme stream: 4 chained with-calls + build
        $builder
            ->withFrom($this->defaultFrom)
            ->withTo($to)
            ->withSubject($subject)
            ->withBody($body);

        $message = $builder->build();
        $message->tag('locale', $locale);
        $message->tag('template', 'welcome');
        return $message;
    }

    public function resetEmail(string $to, string $link): Message
    {
        return $this->welcomeEmail($to, 'Reset your password', 'Click: ' . $link, 'en');
    }
}
