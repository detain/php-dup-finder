<?php
declare(strict_types=1);

namespace Acme\Notifications\Mailers;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

final class PhpMailerMailer
{
    public function __construct(
        private readonly string $templatesDir,
        private readonly string $smtpHost,
        private readonly int $smtpPort,
        private readonly string $smtpUser,
        private readonly string $smtpPass,
        private readonly string $fromAddress,
    ) {}

    /** @param array<string,scalar> $context */
    public function deliver(string $template, string $recipient, array $context): bool
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('bad recipient');
        }
        $file = $this->templatesDir . DIRECTORY_SEPARATOR . $template . '.tpl';
        if (!file_exists($file)) {
            throw new \RuntimeException("template not found: $template");
        }
        $rendered = (string) file_get_contents($file);
        foreach ($context as $k => $v) {
            $rendered = str_replace('%' . strtoupper($k) . '%', htmlspecialchars((string) $v, ENT_QUOTES), $rendered);
        }
        $subject = 'Notification';
        if (preg_match('/<title>(.*?)<\/title>/i', $rendered, $m)) {
            $subject = trim($m[1]);
        }
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $this->smtpHost;
            $mail->Port       = $this->smtpPort;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->smtpUser;
            $mail->Password   = $this->smtpPass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->setFrom($this->fromAddress);
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $rendered;
            $mail->AltBody = strip_tags($rendered);
            return $mail->send();
        } catch (MailerException $e) {
            return false;
        }
    }
}
