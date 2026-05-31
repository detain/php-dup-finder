<?php
declare(strict_types=1);

namespace Acme\Integration\Stripe;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;

final class StripeChargeClient
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
            'base_uri'        => 'https://api.stripe.com/v1/',
            'timeout'         => 30,
            'connect_timeout' => 5,
            'handler'         => $stack,
            'headers'         => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept'        => 'application/json',
                'User-Agent'    => 'acme-stripe/1.0',
            ],
        ]);
    }

    public function charge(string $customerId, int $amountCents, string $currency = 'usd'): array
    {
        $this->log->info('stripe.charge.start', ['customer' => $customerId, 'amount' => $amountCents]);
        $resp = $this->http->post('charges', [
            'form_params' => [
                'customer' => $customerId,
                'amount'   => $amountCents,
                'currency' => $currency,
            ],
        ]);
        $body = (string) $resp->getBody();
        $this->log->info('stripe.charge.ok', ['customer' => $customerId]);

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
