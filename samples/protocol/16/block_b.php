<?php
declare(strict_types=1);

namespace App\Notification\Grpc;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Grpc\BaseStub;
use Grpc\Channel;
use Grpc\ChannelCredentials;
use Grpc\Timeval;

final class NotificationServiceClient extends BaseStub
{
    private LoggerInterface $logger;
    private string $host;
    private int $port;
    private Channel $channel;
    private int $timeout = 30000;
    private int $maxRetries = 3;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->host = $config->get('services.notification.host', 'localhost');
        $this->port = (int)$config->get('services.notification.port', 50052);
        
        $address = $this->host . ':' . $this->port;
        $credentials = ChannelCredentials::createInsecure();
        
        $options = [
            'timeout' => $this->timeout,
        ];
        
        parent::__construct($address, $credentials, $options);
        
        $this->channel = new Channel($address, $credentials, $options);
    }

    public function __destruct()
    {
        $this->channel->close();
    }

    public function sendEmail(SendEmailRequest $request, array $metadata = []): SendResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/notification.NotificationService/SendEmail',
                $request,
                ['\App\Notification\Grpc\SendResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            $this->logger->info('Email notification sent via gRPC', [
                'message_id' => $response->getMessageId(),
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('NotificationService sendEmail failed', [
                'to' => $request->getTo(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function sendSms(SendSmsRequest $request, array $metadata = []): SendResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/notification.NotificationService/SendSms',
                $request,
                ['\App\Notification\Grpc\SendResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            $this->logger->info('SMS notification sent via gRPC', [
                'message_id' => $response->getMessageId(),
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('NotificationService sendSms failed', [
                'to' => $request->getPhoneNumber(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function sendPush(SendPushRequest $request, array $metadata = []): SendResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/notification.NotificationService/SendPush',
                $request,
                ['\App\Notification\Grpc\SendResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            $this->logger->info('Push notification sent via gRPC', [
                'message_id' => $response->getMessageId(),
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('NotificationService sendPush failed', [
                'device_token' => substr($request->getDeviceToken(), 0, 20),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getNotification(GetNotificationRequest $request, array $metadata = []): ?NotificationResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/notification.NotificationService/GetNotification',
                $request,
                ['\App\Notification\Grpc\NotificationResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('NotificationService getNotification failed', [
                'notification_id' => $request->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getNotifications(GetNotificationsRequest $request, array $metadata = []): NotificationsResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/notification.NotificationService/GetNotifications',
                $request,
                ['\App\Notification\Grpc\NotificationsResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('NotificationService getNotifications failed', [
                'user_id' => $request->getUserId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function markAsRead(MarkAsReadRequest $request, array $metadata = []): MarkAsReadResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/notification.NotificationService/MarkAsRead',
                $request,
                ['\App\Notification\Grpc\MarkAsReadResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            $this->logger->info('Notification marked as read via gRPC', [
                'notification_id' => $request->getId(),
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('NotificationService markAsRead failed', [
                'notification_id' => $request->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function markAllAsRead(MarkAllAsReadRequest $request, array $metadata = []): MarkAllAsReadResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/notification.NotificationService/MarkAllAsRead',
                $request,
                ['\App\Notification\Grpc\MarkAllAsReadResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            $this->logger->info('All notifications marked as read via gRPC', [
                'user_id' => $request->getUserId(),
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('NotificationService markAllAsRead failed', [
                'user_id' => $request->getUserId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function isHealthy(): bool
    {
        try {
            $request = new HealthCheckRequest();
            $request->setService('notification.NotificationService');
            
            $deadline = new Timeval(5000);
            
            $response = $this->_simpleRequest(
                '/grpc.health.v1.Health/Check',
                $request,
                ['\Grpc\HealthCheckResponse', 'decode'],
                [],
                $deadline
            );
            
            return $response->getStatus() === \Grpc\HealthCheckResponse::SERVING;
        } catch (\Exception $e) {
            $this->logger->warning('NotificationService health check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
