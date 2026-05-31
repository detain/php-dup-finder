<?php
declare(strict_types=1);

namespace Integrations;

use Auth\Tokens\TokenService;
use Auth\Tokens\Token;
use Psr\Log\LoggerInterface;

final class ScopedTokenSession
{
    public function __construct(private TokenService $tokens, private LoggerInterface $log) {}

    /**
     * @template T
     * @param array{aud:string,sub:string,scope:string,ttl:int} $claims
     * @param callable(Token):T $work
     * @return T
     */
    public function withToken(array $claims, callable $work)
    {
        $token = $this->tokens->issue($claims);
        try {
            return $work($token);
        } finally {
            $this->tokens->revoke($token->id);
            $this->log->debug('token.revoked', [
                'token_id' => $token->id,
                'aud'      => $claims['aud'],
            ]);
        }
    }
}

final class CrmContactSync
{
    public function __construct(private ScopedTokenSession $session, private \GuzzleHttp\Client $http) {}

    public function pushContact(int $userId, array $contact): string
    {
        return $this->session->withToken(
            ['aud' => 'crm', 'sub' => "user:{$userId}", 'scope' => 'contacts.write', 'ttl' => 60],
            function ($token) use ($contact): string {
                $response = $this->http->post('https://crm.example.com/v1/contacts', [
                    'headers' => ['Authorization' => "Bearer {$token->jwt}"],
                    'json'    => $contact,
                    'timeout' => 10,
                ]);
                $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
                return (string) ($body['id'] ?? throw new \RuntimeException('crm: missing id'));
            }
        );
    }
}
