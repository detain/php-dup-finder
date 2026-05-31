<?php
declare(strict_types=1);

namespace App\Webhooks;

final class StripeWebhookDispatcher
{
    public static function dispatch(string $url, array $payload, string $signature): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type: application/json',
            'Stripe-Signature: ' . $signature,
            'User-Agent: AppWebhookClient/1.0',
        ];

        $attempt = 0;
        $lastError = null;

        while ($attempt < 3) {
            $attempt++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($response !== false && $statusCode >= 200 && $statusCode < 300) {
                return [
                    'success'    => true,
                    'attempts'   => $attempt,
                    'status'     => $statusCode,
                    'body'       => $response,
                ];
            }

            $lastError = $err ?: ('HTTP ' . $statusCode);
            error_log("Webhook attempt {$attempt} failed: {$lastError}");

            if ($attempt < 3) {
                $sleepSeconds = (int)pow(2, $attempt);
                sleep($sleepSeconds);
            }
        }

        return [
            'success'   => false,
            'attempts'  => 3,
            'error'     => $lastError,
        ];
    }
}
