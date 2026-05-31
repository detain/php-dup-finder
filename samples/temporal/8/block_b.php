<?php
declare(strict_types=1);

namespace Integrations\Billing;

use Auth\Tokens\TokenService;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

final class BillingInvoicePublisher
{
    public function __construct(
        private TokenService $tokens,
        private Client $http,
        private LoggerInterface $log,
    ) {}

    public function publish(int $userId, array $invoice): string
    {
        $token = $this->tokens->issue([
            'aud'   => 'billing',
            'sub'   => "user:{$userId}",
            'scope' => 'invoices.write',
            'ttl'   => 60,
        ]);
        try {
            $response = $this->http->post('https://billing.example.com/v2/invoices', [
                'headers' => ['Authorization' => "Bearer {$token->jwt}"],
                'json'    => $invoice,
                'timeout' => 10,
            ]);
            $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (!isset($body['invoice_id'])) {
                throw new \RuntimeException('billing: missing invoice id');
            }
            $this->log->info('billing.invoice.published', ['invoice_id' => $body['invoice_id'], 'user' => $userId]);
            return (string) $body['invoice_id'];
        } finally {
            $this->tokens->revoke($token->id);
            $this->log->debug('billing.token.revoked', ['token_id' => $token->id]);
        }
    }
}
