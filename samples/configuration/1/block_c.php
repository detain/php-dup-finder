<?php
declare(strict_types=1);

namespace Acme\Integration\Sendgrid;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;

final class SendgridMailClient
{
    private Client $http;

    public function __construct(private string $apiKey, private LoggerInterface $log)
    {
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
            'base_uri'        => 'https://api.sendgrid.com/v3/',
            'timeout'         => 30,
            'connect_timeout' => 5,
            'handler'         => $stack,
            'headers'         => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'acme-sendgrid/1.0',
            ],
        ]);
    }

    public function sendMail(string $to, string $from, string $subject, string $html): void
    {
        $this->log->info('sendgrid.send.start', ['to' => $to]);
        $this->http->post('mail/send', [
            'json' => [
                'personalizations' => [['to' => [['email' => $to]]]],
                'from'             => ['email' => $from],
                'subject'          => $subject,
                'content'          => [['type' => 'text/html', 'value' => $html]],
            ],
        ]);
        $this->log->info('sendgrid.send.ok', ['to' => $to]);
    }
}
