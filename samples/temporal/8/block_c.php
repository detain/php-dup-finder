<?php
declare(strict_types=1);

namespace Integrations\Marketing;

use Auth\Tokens\TokenService;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

final class MarketingAudienceSync
{
    public function __construct(
        private TokenService $tokens,
        private Client $http,
        private LoggerInterface $log,
    ) {}

    public function enrol(int $userId, string $audienceId, array $traits): bool
    {
        $token = $this->tokens->issue([
            'aud'   => 'marketing',
            'sub'   => "user:{$userId}",
            'scope' => 'audiences.write',
            'ttl'   => 60,
        ]);
        try {
            $response = $this->http->post("https://marketing.example.com/v1/audiences/{$audienceId}/members", [
                'headers' => ['Authorization' => "Bearer {$token->jwt}"],
                'json'    => ['user_id' => $userId, 'traits' => $traits],
                'timeout' => 10,
            ]);
            $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (!isset($body['enrolled'])) {
                throw new \RuntimeException('marketing: missing enrolled flag');
            }
            $this->log->info('marketing.enrol.ok', ['user' => $userId, 'audience' => $audienceId]);
            return (bool) $body['enrolled'];
        } finally {
            $this->tokens->revoke($token->id);
            $this->log->debug('marketing.token.revoked', ['token_id' => $token->id]);
        }
    }
}
