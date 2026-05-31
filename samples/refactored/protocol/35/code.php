<?php
declare(strict_types=1);

namespace App\Auth\Jwt;

use App\Logging\LoggerInterface;

final class JwtValidator
{
    private LoggerInterface $logger;
    private string $secretKey;
    private int $leewaySeconds;
    private array $supportedAlgorithms = ['HS256', 'HS384', 'HS512'];

    public function __construct(
        string $secretKey,
        LoggerInterface $logger,
        int $leewaySeconds = 60
    ) {
        $this->secretKey = $secretKey;
        $this->logger = $logger;
        $this->leewaySeconds = $leewaySeconds;
    }

    public function validate(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            
            if (count($parts) !== 3) {
                $this->logger->warning('JWT token has invalid structure');
                return null;
            }
            
            [$headerB64, $payloadB64, $signatureB64] = $parts;
            
            $header = json_decode($this->base64UrlDecode($headerB64), true);
            $payload = json_decode($this->base64UrlDecode($payloadB64), true);
            
            if (!$this->verifySignature($headerB64 . '.' . $payloadB64, $signatureB64, $header)) {
                $this->logger->warning('JWT signature verification failed');
                return null;
            }
            
            if (!$this->isExpired($payload)) {
                $this->logger->warning('JWT token is expired');
                return null;
            }
            
            if (!$this->isNotBefore($payload)) {
                $this->logger->warning('JWT token is not yet valid');
                return null;
            }
            
            $this->logger->debug('JWT token validated successfully');
            return $payload;
            
        } catch (\Exception $e) {
            $this->logger->error('JWT validation error', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getUserId(array $payload): ?string
    {
        return $payload['sub'] ?? $payload['user_id'] ?? null;
    }

    public function getScopes(array $payload): array
    {
        $scopes = $payload['scopes'] ?? $payload['scope'] ?? [];
        
        if (is_string($scopes)) {
            $scopes = explode(' ', $scopes);
        }
        
        return $scopes;
    }

    public function hasScope(array $payload, string $requiredScope): bool
    {
        $scopes = $this->getScopes($payload);
        return in_array($requiredScope, $scopes, true);
    }

    private function verifySignature(string $data, string $signature, array $header): bool
    {
        $algorithm = $header['alg'] ?? 'HS256';
        
        if (!in_array($algorithm, $this->supportedAlgorithms, true)) {
            $this->logger->warning('Unsupported JWT algorithm', ['alg' => $algorithm]);
            return false;
        }
        
        $hashAlgorithm = match ($algorithm) {
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512',
            default => false,
        };
        
        $expectedSignature = base64_encode(
            hash_hmac($hashAlgorithm, $data, $this->secretKey, true)
        );
        
        $signature = $this->base64UrlDecode($signature);
        $expectedSignature = $this->base64UrlDecode($expectedSignature);
        
        if (strlen($signature) !== strlen($expectedSignature)) {
            return false;
        }
        
        return hash_equals($expectedSignature, $signature);
    }

    private function isExpired(array $payload): bool
    {
        if (!isset($payload['exp'])) {
            return false;
        }
        
        return time() > ($payload['exp'] - $this->leewaySeconds);
    }

    private function isNotBefore(array $payload): bool
    {
        if (!isset($payload['nbf'])) {
            return true;
        }
        
        return time() >= ($payload['nbf'] - $this->leewaySeconds);
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
