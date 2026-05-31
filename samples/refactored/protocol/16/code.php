<?php
declare(strict_types=1);

namespace App\Grpc;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Grpc\BaseStub;
use Grpc\Channel;
use Grpc\ChannelCredentials;
use Grpc\Timeval;

abstract class AbstractGrpcClient extends BaseStub
{
    protected LoggerInterface $logger;
    protected string $serviceName;
    protected string $host;
    protected int $port;
    protected Channel $channel;
    protected int $timeout = 30000;
    protected int $maxRetries = 3;

    abstract protected function getConfigPrefix(): string;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $prefix = $this->getConfigPrefix();
        
        $this->host = $config->get($prefix . '.host', 'localhost');
        $this->port = (int)$config->get($prefix . '.port', 50051);
        $this->timeout = (int)$config->get($prefix . '.timeout', 30000);
        $this->maxRetries = (int)$config->get($prefix . '.max_retries', 3);
        
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
        if (isset($this->channel)) {
            $this->channel->close();
        }
    }

    protected function call(string $method, $request, string $responseClass, array $metadata = []): ?object
    {
        $deadline = new Timeval($this->timeout);
        
        try {
            $response = $this->_simpleRequest(
                '/' . $this->serviceName . '/' . $method,
                $request,
                [$responseClass, 'decode'],
                $metadata,
                $deadline
            );
            
            $this->logger->debug($this->serviceName . ' gRPC call succeeded', [
                'method' => $method,
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error($this->serviceName . ' gRPC call failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function logOperation(string $operation, array $context = []): void
    {
        $this->logger->info($this->serviceName . ' gRPC operation', array_merge([
            'operation' => $operation,
        ], $context));
    }

    public function isHealthy(): bool
    {
        try {
            $request = new HealthCheckRequest();
            $request->setService($this->serviceName);
            
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
            $this->logger->warning($this->serviceName . ' health check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }
}
