<?php
declare(strict_types=1);

namespace App\Api\OAuth;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

abstract class AbstractOAuth1Client
{
    protected ConfigManager $config;
    protected LoggerInterface $logger;
    protected string $consumerKey;
    protected string $consumerSecret;
    protected string $accessToken;
    protected string $accessTokenSecret;

    abstract protected function getApiBaseUrl(): string;

    public function get(string $endpoint, array $params = []): array
    {
        $url = $this->buildUrl($endpoint);
        $headers = $this->buildAuthorizationHeader($url, 'GET', $params);
        
        $this->logger->debug(static::class . ' API request', [
            'endpoint' => $endpoint,
            'method' => 'GET',
        ]);
        
        return $this->makeRequest('GET', $url, $headers, $params);
    }

    public function post(string $endpoint, array $data = []): array
    {
        $url = $this->buildUrl($endpoint);
        $headers = $this->buildAuthorizationHeader($url, 'POST', [], $data);
        
        $this->logger->debug(static::class . ' API request', [
            'endpoint' => $endpoint,
            'method' => 'POST',
        ]);
        
        return $this->makeRequest('POST', $url, $headers, $data);
    }

    protected function buildAuthorizationHeader(
        string $url,
        string $method,
        array $params = [],
        array $body = []
    ): array {
        $timestamp = (string)time();
        $nonce = $this->generateNonce();
        
        $oauthParams = [
            'oauth_consumer_key' => $this->consumerKey,
            'oauth_nonce' => $nonce,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => $timestamp,
            'oauth_token' => $this->accessToken,
            'oauth_version' => '1.0',
        ];
        
        $signature = $this->generateSignature($url, $method, $oauthParams, $params, $body);
        $oauthParams['oauth_signature'] = $signature;
        
        $header = 'OAuth ';
        $parts = [];
        
        foreach ($oauthParams as $key => $value) {
            $parts[] = sprintf('%s="%s"', $this->urlEncode($key), $this->urlEncode($value));
        }
        
        $header .= implode(', ', $parts);
        
        return ['Authorization' => $header];
    }

    protected function generateSignature(
        string $url,
        string $method,
        array $oauthParams,
        array $params,
        array $body
    ): string {
        $baseStringParams = array_merge($oauthParams, $params, $body);
        ksort($baseStringParams);
        
        $pairs = [];
        foreach ($baseStringParams as $key => $value) {
            $pairs[] = sprintf('%s=%s', $this->urlEncode($key), $this->urlEncode((string)$value));
        }
        
        $parameterString = implode('&', $pairs);
        
        $baseString = sprintf(
            '%s&%s&%s',
            strtoupper($method),
            $this->urlEncode($url),
            $this->urlEncode($parameterString)
        );
        
        $signingKey = sprintf(
            '%s&%s',
            $this->urlEncode($this->consumerSecret),
            $this->urlEncode($this->accessTokenSecret)
        );
        
        return base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));
    }

    protected function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function urlEncode(string $value): string
    {
        return rawurlencode($value);
    }

    protected function buildUrl(string $endpoint): string
    {
        return rtrim($this->getApiBaseUrl(), '/') . '/' . ltrim($endpoint, '/');
    }

    abstract protected function makeRequest(
        string $method,
        string $url,
        array $headers,
        array $data = []
    ): array;
}
