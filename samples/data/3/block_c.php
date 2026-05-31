<?php
declare(strict_types=1);

namespace App\Mailgun;

final class MailgunSender
{
    public static function send(string $from, string $to, string $subject, string $html): bool
    {
        $apiKey = getenv('MAILGUN_API_KEY') ?: '';
        $domain = getenv('MAILGUN_DOMAIN') ?: 'mg.example.com';

        if ($apiKey === '') {
            throw new \RuntimeException('MAILGUN_API_KEY not configured');
        }

        $url = "https://api.mailgun.net/v3/{$domain}/messages";

        $attempt = 0;
        $lastError = '';

        while ($attempt < 3) {
            $attempt++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_USERPWD        => 'api:' . $apiKey,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'from'    => $from,
                    'to'      => $to,
                    'subject' => $subject,
                    'html'    => $html,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
            ]);

            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastError = curl_error($ch);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                error_log("Mailgun sent successfully on attempt {$attempt}");
                return true;
            }

            error_log("Mailgun attempt {$attempt} returned {$code}: {$lastError}");

            if ($attempt < 3) {
                sleep(2 * $attempt);
            }
        }

        error_log("Mailgun gave up after 3 attempts to {$to}: {$lastError}");
        return false;
    }
}
