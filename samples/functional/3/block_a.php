<?php
declare(strict_types=1);

namespace Acme\Notifications\Mailers;

final class NativeMailMailer
{
    public function __construct(
        private readonly string $templatesDir,
        private readonly string $fromAddress,
        private readonly string $fromName,
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
        $tplPath = $this->templatesDir . '/' . $template . '.html';
        if (!is_readable($tplPath)) {
            throw new \RuntimeException("missing template: $template");
        }
        $body = (string) file_get_contents($tplPath);
        foreach ($context as $key => $value) {
            $body = str_replace('{{ ' . $key . ' }}', htmlspecialchars((string) $value, ENT_QUOTES), $body);
        }
        $subjectLine = $this->extractSubject($body);
        $boundary    = bin2hex(random_bytes(8));
        $headers  = "From: {$this->fromName} <{$this->fromAddress}>\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $headers .= "Reply-To: {$this->fromAddress}\r\n";
        $headers .= "X-Mailer: NativeMailMailer\r\n";
        $plain = strip_tags($body);
        $message  = "--$boundary\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $message .= $plain . "\r\n\r\n";
        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $message .= $body . "\r\n\r\n";
        $message .= "--$boundary--";
        return @mail($to, $subjectLine, $message, $headers);
    }

    private function extractSubject(string $html): string
    {
        if (preg_match('/<title>(.*?)<\/title>/i', $html, $m)) {
            return trim($m[1]);
        }
        return 'Notification';
    }
}
