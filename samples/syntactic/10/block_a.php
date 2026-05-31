<?php
declare(strict_types=1);

namespace Acme\Email;

final class EmailBuilder
{
    private string $subject = '';
    private string $from = '';
    private array $to = [];
    private array $headers = [];
    private string $body = '';

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function from(string $from): self
    {
        $this->from = $from;
        return $this;
    }

    public function to(array $to): self
    {
        $this->to = $to;
        return $this;
    }

    public function headers(array $headers): self
    {
        $this->headers = $headers;
        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function build(): EmailMessage
    {
        return new EmailMessage(
            subject: $this->subject,
            from:    $this->from,
            to:      $this->to,
            headers: $this->headers,
            body:    $this->body,
        );
    }
}
