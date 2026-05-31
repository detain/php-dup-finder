<?php
declare(strict_types=1);

namespace Acme\Integration\Twilio;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;

final class TwilioSmsClient
{
    private Client $http;

    public function __construct(
        private string $accountSid,
        private string $authToken,
        private LoggerInterface $log,
    ) {
        $stack = HandlerStack::create();
        $stack->push(Middleware::retry(
            function (int $retries, Request $req, $resp = null, $err = null): bool {
                if ($retries >= 5) {
                    return false;
                }
                if ($err !== null) {
                    return true;
                }
                return $resp !== null && $resp->getStatusCode() >= 500;
            },
            fn (int $retries): int => (int) (250 * (2 ** $retries))
        ));

        $this->http = new Client([
            'base_uri'        => 'https://api.twilio.com/2010-04-01/',
            'timeout'         => 30,
            'connect_timeout' => 5,
            'handler'         => $stack,
            'auth'            => [$this->accountSid, $this->authToken],
            'headers'         => [
                'Accept'     => 'application/json',
                'User-Agent' => 'acme-twilio/1.0',
            ],
        ]);
    }

    public function sendSms(string $from, string $to, string $body): string
    {
        $this->log->info('twilio.sms.start', ['to' => $to]);
        $resp = $this->http->post("Accounts/{$this->accountSid}/Messages.json", [
            'form_params' => ['From' => $from, 'To' => $to, 'Body' => $body],
        ]);
        $decoded = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->log->info('twilio.sms.ok', ['sid' => $decoded['sid'] ?? null]);

        return (string) $decoded['sid'];
    }
}
