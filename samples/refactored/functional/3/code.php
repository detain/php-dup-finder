<?php
declare(strict_types=1);

namespace Acme\Notifications;

interface MailTransport
{
    public function deliver(string $to, string $subject, string $html, string $text): bool;
}

final class TemplatedMailer
{
    public function __construct(
        private readonly string $templatesDir,
        private readonly MailTransport $transport,
    ) {
        if (!is_dir($templatesDir)) {
            throw new \InvalidArgumentException('templates dir missing');
        }
    }

    /** @param array<string,scalar> $context */
    public function send(string $template, string $to, array $context): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('invalid recipient');
        }
        $path = $this->templatesDir . '/' . $template . '.html';
        if (!is_readable($path)) {
            throw new \RuntimeException("template not found: $template");
        }
        $html = (string) file_get_contents($path);
        foreach ($context as $key => $value) {
            $html = str_replace('{{ ' . $key . ' }}', htmlspecialchars((string) $value, ENT_QUOTES), $html);
        }
        $subject = preg_match('/<title>(.*?)<\/title>/i', $html, $m) ? trim($m[1]) : 'Notification';
        return $this->transport->deliver($to, $subject, $html, strip_tags($html));
    }
}
