<?php
declare(strict_types=1);

namespace App\Api\OAuth;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

final class QuickBooksApiClient
{
    private ConfigManager $config;
    private LoggerInterface $logger;
    private string $consumerKey;
    private string $consumerSecret;
    private string $accessToken;
    private string $accessTokenSecret;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->consumerKey = $config->get('quickbooks.consumer_key');
        $this->consumerSecret = $config->get('quickbooks.consumer_secret');
        $this->accessToken = $config->get('quickbooks.access_token');
        $this->accessTokenSecret = $config->get('quickbooks.access_token_secret');
    }

    public function get(string $endpoint, array $params = []): array
    {
        $url = $this->buildUrl($endpoint);
        $headers = $this->buildAuthorizationHeader($url, 'GET', $params);
        
        $this->logger->debug('QuickBooks API request', [
            'endpoint' => $endpoint,
            'method' => 'GET',
        ]);
        
        return $this->makeRequest('GET', $url, $headers, $params);
    }

    public function post(string $endpoint, array $data = []): array
    {
        $url = $this->buildUrl($endpoint);
        $headers = $this->buildAuthorizationHeader($url, 'POST', [], $data);
        
        $this->logger->debug('QuickBooks API request', [
            'endpoint' => $endpoint,
            'method' => 'POST',
        ]);
        
        return $this->makeRequest('POST', $url, $headers, $data);
    }

    private function buildAuthorizationHeader(
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

    private function generateSignature(
        string $url,
        string $method,
        array $oauthParams,
        array $params,
        array $body
    ): string {
        $baseStringParams = array_merge($oauthParams, $params, $body);
        ksort($baseStringParams);
        
        $parameterString = '';
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

    private function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function urlEncode(string $value): string
    {
        return rawurlencode($value);
    }

    private function buildUrl(string $endpoint): string
    {
        return 'https://quickbooks.api.intuit.com/v3/' . ltrim($endpoint, '/');
    }

    private function makeRequest(
        string $method,
        string $url,
        array $headers,
        array $data = []
    ): array {
        return [];
    }
}
