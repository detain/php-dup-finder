<?php
declare(strict_types=1);

namespace Acme\Notifications\Mailers;

final class QueuedMailer
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $queueKey,
        private readonly string $templatesDir,
    ) {}

    /** @param array<string,scalar> $context */
    public function dispatch(string $templateName, string $emailAddress, array $context): bool
    {
        if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('recipient is not an email');
        }
        $templatePath = $this->templatesDir . '/' . $templateName . '.twig';
        if (!is_file($templatePath)) {
            throw new \RuntimeException("template missing: $templateName");
        }
        $raw = (string) file_get_contents($templatePath);
        foreach ($context as $key => $value) {
            $raw = str_replace('{{' . $key . '}}', htmlspecialchars((string) $value, ENT_QUOTES), $raw);
        }
        $subject = 'Notification';
        if (preg_match('/<title>(.*?)<\/title>/i', $raw, $m)) {
            $subject = trim($m[1]);
        }
        $payload = json_encode([
            'id'      => bin2hex(random_bytes(8)),
            'to'      => $emailAddress,
            'subject' => $subject,
            'html'    => $raw,
            'text'    => strip_tags($raw),
            'context' => $context,
            'enqueued_at' => time(),
        ], JSON_THROW_ON_ERROR);
        try {
            $this->redis->rPush($this->queueKey, $payload);
        } catch (\RedisException $e) {
            return false;
        }
        return true;
    }
}
