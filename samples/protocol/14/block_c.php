<?php
declare(strict_types=1);

namespace App\Notifications\Sms;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

final class TwilioSmsClient
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;
    private string $messagingServiceSid;
    private int $connectTimeout = 30;
    private int $timeout = 60;
    private int $maxRetries = 3;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->accountSid = $config->get('twilio.account_sid');
        $this->authToken = $config->get('twilio.auth_token');
        $this->fromNumber = $config->get('twilio.from_number');
        $this->messagingServiceSid = $config->get('twilio.messaging_service_sid');
        
        $this->httpClient = new Client([
            'base_uri' => 'https://api.twilio.com/2010-04-01/',
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'auth' => [$this->accountSid, $this->authToken],
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'twilio-php/' . PHP_VERSION,
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
                    $this->logger->warning('Twilio API server error, retrying', [
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
            $this->logger->debug('Twilio API request', [
                'method' => $request->getMethod(),
                'uri' => $request->getUri()->getPath(),
            ]);
            return $request;
        }));
        
        $stack->push(Middleware::mapResponse(function (Response $response) {
            $this->logger->debug('Twilio API response', [
                'status' => $response->getStatusCode(),
            ]);
            return $response;
        }));
        
        return $stack;
    }

    public function sendSms(string $to, string $body, array $options = []): array
    {
        try {
            $params = [
                'To' => $to,
                'From' => $options['from'] ?? $this->fromNumber,
                'Body' => $body,
            ];
            
            if (!empty($this->messagingServiceSid) && empty($options['from'])) {
                unset($params['From']);
                $params['MessagingServiceSid'] = $this->messagingServiceSid;
            }
            
            if (isset($options['status_callback'])) {
                $params['StatusCallback'] = $options['status_callback'];
            }
            
            if (isset($options['max_price'])) {
                $params['MaxPrice'] = $options['max_price'];
            }
            
            if (isset($options['validity_period'])) {
                $params['ValidityPeriod'] = $options['validity_period'];
            }
            
            $response = $this->httpClient->post(
                'Accounts/' . $this->accountSid . '/Messages.json',
                ['form_params' => $params]
            );
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('Twilio SMS send failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            throw new SmsException('Twilio SMS send failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getMessage(string $messageSid): array
    {
        try {
            $response = $this->httpClient->get(
                'Accounts/' . $this->accountSid . '/Messages/' . $messageSid . '.json'
            );
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('Twilio message lookup failed', [
                'message_sid' => $messageSid,
                'error' => $e->getMessage(),
            ]);
            throw new SmsException('Twilio message lookup failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getMessageLogs(array $params = []): array
    {
        try {
            $query = http_build_query(array_merge([
                'PageSize' => 100,
            ], $params));
            
            $response = $this->httpClient->get(
                'Accounts/' . $this->accountSid . '/Messages.json?' . $query
            );
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('Twilio message logs failed', [
                'error' => $e->getMessage(),
            ]);
            throw new SmsException('Twilio message logs failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getPricing(string $countryCode): array
    {
        try {
            $response = $this->httpClient->get(
                'Accounts/' . $this->accountSid . '/Messaging/Services/' . $this->messagingServiceSid . '/PhoneNumbers/' . $countryCode
            );
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('Twilio pricing lookup failed', [
                'country_code' => $countryCode,
                'error' => $e->getMessage(),
            ]);
            throw new SmsException('Twilio pricing lookup failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }
}
