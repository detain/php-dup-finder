<?php
declare(strict_types=1);

namespace App\Notifications\Email;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

final class SendGridEmailClient
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private string $apiKey;
    private string $fromEmail;
    private string $fromName;
    private int $connectTimeout = 30;
    private int $timeout = 60;
    private int $maxRetries = 3;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->apiKey = $config->get('sendgrid.api_key');
        $this->fromEmail = $config->get('sendgrid.from_email');
        $this->fromName = $config->get('sendgrid.from_name', 'System');
        
        $this->httpClient = new Client([
            'base_uri' => 'https://api.sendgrid.com/v3/',
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'User-Agent' => 'sendgrid-php/' . PHP_VERSION,
            ],
            'handler' => $this->createHandlerStack(),
        ]);
    }

    private function createHandlerStack(): HandlerStack
    {
        $stack = HandlerStack::create();
        
        $stack->push(Middleware::retry(
            function ($retries, Request $request, ?Response $response, ?\Exception $e) {
                if ($retries >= $this->maxRetries) {
                    return false;
                }
                
                if ($response && $response->getStatusCode() >= 500) {
                    $this->logger->warning('SendGrid API server error, retrying', [
                        'retry' => $retries + 1,
                        'status' => $response->getStatusCode(),
                    ]);
                    return true;
                }
                
                if ($response && $response->getStatusCode() === 429) {
                    $retryAfter = $response->getHeaderLine('Retry-After');
                    if ($retryAfter) {
                        sleep((int)$retryAfter);
                    }
                    return true;
                }
                
                if ($e instanceof GuzzleException && 
                    (str_contains($e->getMessage(), 'ECONNRESET') || 
                     str_contains($e->getMessage(), 'timeout'))) {
                    return true;
                }
                
                return false;
            },
            function ($retries) {
                return (int)pow(2, $retries) * 100;
            }
        ));
        
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            $this->logger->debug('SendGrid API request', [
                'method' => $request->getMethod(),
                'uri' => $request->getUri()->getPath(),
            ]);
            return $request;
        }));
        
        $stack->push(Middleware::mapResponse(function (Response $response) {
            $this->logger->debug('SendGrid API response', [
                'status' => $response->getStatusCode(),
            ]);
            return $response;
        }));
        
        return $stack;
    }

    public function sendEmail(array $emailData): array
    {
        try {
            $payload = [
                'personalizations' => [
                    [
                        'to' => $this->formatRecipients($emailData['to']),
                        'subject' => $emailData['subject'],
                    ],
                ],
                'from' => [
                    'email' => $emailData['from'] ?? $this->fromEmail,
                    'name' => $emailData['from_name'] ?? $this->fromName,
                ],
                'content' => [
                    [
                        'type' => $emailData['type'] ?? 'text/plain',
                        'value' => $emailData['body'],
                    ],
                ],
            ];
            
            if (isset($emailData['cc'])) {
                $payload['personalizations'][0]['cc'] = $this->formatRecipients($emailData['cc']);
            }
            
            if (isset($emailData['bcc'])) {
                $payload['personalizations'][0]['bcc'] = $this->formatRecipients($emailData['bcc']);
            }
            
            if (isset($emailData['attachments'])) {
                $payload['attachments'] = $emailData['attachments'];
            }
            
            $response = $this->httpClient->post('mail/send', [
                'json' => $payload,
            ]);
            
            return [
                'status' => $response->getStatusCode(),
                'message_id' => $response->getHeaderLine('X-Message-Id'),
            ];
            
        } catch (GuzzleException $e) {
            $this->logger->error('SendGrid email send failed', [
                'to' => $emailData['to'],
                'error' => $e->getMessage(),
            ]);
            throw new EmailException('SendGrid email send failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    private function formatRecipients(array $recipients): array
    {
        return array_map(function ($recipient) {
            if (is_string($recipient)) {
                return ['email' => $recipient];
            }
            return $recipient;
        }, $recipients);
    }

    public function getBatchStatus(): array
    {
        try {
            $response = $this->httpClient->get('mail/batch');
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('SendGrid batch status failed', [
                'error' => $e->getMessage(),
            ]);
            throw new EmailException('SendGrid batch status failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getEmailStats(array $params = []): array
    {
        try {
            $query = http_build_query(array_merge([
                'start_date' => date('Y-m-d', strtotime('-30 days')),
                'end_date' => date('Y-m-d'),
            ], $params));
            
            $response = $this->httpClient->get('v3/api_stats?' . $query);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('SendGrid stats request failed', [
                'error' => $e->getMessage(),
            ]);
            throw new EmailException('SendGrid stats request failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function toggleEmailSettings(bool $sandboxMode): array
    {
        try {
            $response = $this->httpClient->patch('mail/settings', [
                'json' => [
                    'sandbox_mode' => [
                        'enabled' => $sandboxMode,
                    ],
                ],
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('SendGrid settings update failed', [
                'error' => $e->getMessage(),
            ]);
            throw new EmailException('SendGrid settings update failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }
}
