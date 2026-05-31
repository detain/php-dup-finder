<?php
declare(strict_types=1);

namespace Integrations\Crm;

use Auth\Tokens\TokenService;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

final class CrmContactSync
{
    public function __construct(
        private TokenService $tokens,
        private Client $http,
        private LoggerInterface $log,
    ) {}

    public function pushContact(int $userId, array $contact): string
    {
        $token = $this->tokens->issue([
            'aud'   => 'crm',
            'sub'   => "user:{$userId}",
            'scope' => 'contacts.write',
            'ttl'   => 60,
        ]);
        try {
            $response = $this->http->post('https://crm.example.com/v1/contacts', [
                'headers' => ['Authorization' => "Bearer {$token->jwt}"],
                'json'    => $contact,
                'timeout' => 10,
            ]);
            $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (!isset($body['id'])) {
                throw new \RuntimeException('crm: missing id in response');
            }
            $this->log->info('crm.contact.pushed', ['crm_id' => $body['id'], 'user' => $userId]);
            return (string) $body['id'];
        } finally {
            $this->tokens->revoke($token->id);
            $this->log->debug('crm.token.revoked', ['token_id' => $token->id]);
        }
    }
}
