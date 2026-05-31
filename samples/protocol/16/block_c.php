<?php
declare(strict_types=1);

namespace App\Analytics\Grpc;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Grpc\BaseStub;
use Grpc\Channel;
use Grpc\ChannelCredentials;
use Grpc\Timeval;

final class AnalyticsServiceClient extends BaseStub
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
        $this->host = $config->get('services.analytics.host', 'localhost');
        $this->port = (int)$config->get('services.analytics.port', 50053);
        
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

    public function trackEvent(TrackEventRequest $request, array $metadata = []): TrackResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/analytics.AnalyticsService/TrackEvent',
                $request,
                ['\App\Analytics\Grpc\TrackResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            $this->logger->debug('Event tracked via gRPC', [
                'event_name' => $request->getEventName(),
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('AnalyticsService trackEvent failed', [
                'event_name' => $request->getEventName(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function trackBatch(TrackBatchRequest $request, array $metadata = []): TrackBatchResponse
    {
        $deadline = new Timeval($this->timeout * 2);
        
        try {
            $response = $this->_simpleRequest(
                '/analytics.AnalyticsService/TrackBatch',
                $request,
                ['\App\Analytics\Grpc\TrackBatchResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            $this->logger->info('Batch events tracked via gRPC', [
                'count' => count($request->getEvents()),
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('AnalyticsService trackBatch failed', [
                'event_count' => count($request->getEvents()),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getUserMetrics(GetUserMetricsRequest $request, array $metadata = []): UserMetricsResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/analytics.AnalyticsService/GetUserMetrics',
                $request,
                ['\App\Analytics\Grpc\UserMetricsResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('AnalyticsService getUserMetrics failed', [
                'user_id' => $request->getUserId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getSessionMetrics(GetSessionMetricsRequest $request, array $metadata = []): SessionMetricsResponse
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/analytics.AnalyticsService/GetSessionMetrics',
                $request,
                ['\App\Analytics\Grpc\SessionMetricsResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('AnalyticsService getSessionMetrics failed', [
                'session_id' => $request->getSessionId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getFunnelMetrics(GetFunnelMetricsRequest $request, array $metadata = []): FunnelMetricsResponse
    {
        $deadline = new Timeval($this->timeout * 2);
        
        try {
            $response = $this->_simpleRequest(
                '/analytics.AnalyticsService/GetFunnelMetrics',
                $request,
                ['\App\Analytics\Grpc\FunnelMetricsResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('AnalyticsService getFunnelMetrics failed', [
                'funnel_id' => $request->getFunnelId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getRetentionMetrics(GetRetentionMetricsRequest $request, array $metadata = []): RetentionMetricsResponse
    {
        $deadline = new Timeval($this->timeout * 2);
        
        try {
            $response = $this->_simpleRequest(
                '/analytics.AnalyticsService/GetRetentionMetrics',
                $request,
                ['\App\Analytics\Grpc\RetentionMetricsResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('AnalyticsService getRetentionMetrics failed', [
                'cohort_id' => $request->getCohortId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getReport(GetReportRequest $request, array $metadata = []): ReportResponse
    {
        $deadline = new Timeval($this->timeout * 3);
        
        try {
            $response = $this->_simpleRequest(
                '/analytics.AnalyticsService/GetReport',
                $request,
                ['\App\Analytics\Grpc\ReportResponse', 'decode'],
                $metadata,
                $deadline
            );
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('AnalyticsService getReport failed', [
                'report_id' => $request->getReportId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function isHealthy(): bool
    {
        try {
            $request = new HealthCheckRequest();
            $request->setService('analytics.AnalyticsService');
            
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
            $this->logger->warning('AnalyticsService health check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
