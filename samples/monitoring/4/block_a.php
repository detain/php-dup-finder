<?php

declare(strict_types=1);

namespace App\Health;

class HealthCheckManager
{
    private HealthChecker $healthChecker;
    private LoggerInterface $logger;

    public function __construct(HealthChecker $healthChecker, LoggerInterface $logger)
    {
        $this->healthChecker = $healthChecker;
        $this->logger = $logger;
    }

    public function checkAllSystems(): HealthCheckResult
    {
        $startTime = microtime(true);

        $results = [
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth(),
            'queue' => $this->checkQueueHealth(),
            'storage' => $this->checkStorageHealth(),
            'external_services' => $this->checkExternalServicesHealth()
        ];

        $allHealthy = true;
        $unhealthyComponents = [];

        foreach ($results as $component => $result) {
            if (!$result->isHealthy()) {
                $allHealthy = false;
                $unhealthyComponents[] = $component;
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        return new HealthCheckResult(
            healthy: $allHealthy,
            duration: $duration,
            checks: $results,
            unhealthyComponents: $unhealthyComponents
        );
    }

    public function checkDatabaseHealth(): ComponentHealth
    {
        try {
            $this->healthChecker->checkDatabaseConnection();

            $this->healthChecker->checkDatabaseCanRead();

            $this->healthChecker->checkDatabaseCanWrite();

            $this->healthChecker->checkDatabaseReplicationStatus();

            $this->healthChecker->checkDatabaseConnectionPool();

            return new ComponentHealth(
                healthy: true,
                message: 'Database is healthy'
            );
        } catch (\Exception $e) {
            $this->logger->error('Database health check failed', [
                'error' => $e->getMessage()
            ]);

            return new ComponentHealth(
                healthy: false,
                message: 'Database is unhealthy: ' . $e->getMessage()
            );
        }
    }

    public function checkCacheHealth(): ComponentHealth
    {
        try {
            $this->healthChecker->checkRedisConnection();

            $this->healthChecker->checkRedisPing();

            $this->healthChecker->checkRedisMemoryUsage();

            $this->healthChecker->checkRedisEvictionPolicy();

            return new ComponentHealth(
                healthy: true,
                message: 'Cache is healthy'
            );
        } catch (\Exception $e) {
            $this->logger->error('Cache health check failed', [
                'error' => $e->getMessage()
            ]);

            return new ComponentHealth(
                healthy: false,
                message: 'Cache is unhealthy: ' . $e->getMessage()
            );
        }
    }

    public function checkQueueHealth(): ComponentHealth
    {
        try {
            $this->healthChecker->checkRabbitMqConnection();

            $this->healthChecker->checkRabbitMqQueues();

            $this->healthChecker->checkRabbitMqMemoryUsage();

            $this->healthChecker->checkKafkaBrokers();

            $this->healthChecker->checkKafkaConsumerLags();

            return new ComponentHealth(
                healthy: true,
                message: 'Queue is healthy'
            );
        } catch (\Exception $e) {
            $this->logger->error('Queue health check failed', [
                'error' => $e->getMessage()
            ]);

            return new ComponentHealth(
                healthy: false,
                message: 'Queue is unhealthy: ' . $e->getMessage()
            );
        }
    }

    public function checkStorageHealth(): ComponentHealth
    {
        try {
            $this->healthChecker->checkLocalDiskSpace();

            $this->healthChecker->checkNfsMounts();

            $this->healthChecker->checkS3Connection();

            $this->healthChecker->checkFilePermissions();

            return new ComponentHealth(
                healthy: true,
                message: 'Storage is healthy'
            );
        } catch (\Exception $e) {
            $this->logger->error('Storage health check failed', [
                'error' => $e->getMessage()
            ]);

            return new ComponentHealth(
                healthy: false,
                message: 'Storage is unhealthy: ' . $e->getMessage()
            );
        }
    }

    public function checkExternalServicesHealth(): ComponentHealth
    {
        try {
            $this->healthChecker->checkPaymentGateway();

            $this->healthChecker->checkEmailService();

            $this->healthChecker->checkSmsService();

            $this->healthChecker->checkCloudStorage();

            return new ComponentHealth(
                healthy: true,
                message: 'All external services are healthy'
            );
        } catch (\Exception $e) {
            $this->logger->error('External services health check failed', [
                'error' => $e->getMessage()
            ]);

            return new ComponentHealth(
                healthy: false,
                message: 'External services unhealthy: ' . $e->getMessage()
            );
        }
    }

    public function checkSingleComponent(string $component): ComponentHealth
    {
        return match($component) {
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth(),
            'queue' => $this->checkQueueHealth(),
            'storage' => $this->checkStorageHealth(),
            'external_services' => $this->checkExternalServicesHealth(),
            default => new ComponentHealth(false, "Unknown component: {$component}")
        };
    }
}

class HealthChecker
{
    private PDO $database;
    private Redis $redis;
    private AMQPConnection $rabbitMq;
    private LoggerInterface $logger;

    public function checkDatabaseConnection(): void
    {
        $this->database->query('SELECT 1');
    }

    public function checkDatabaseCanRead(): void
    {
        $result = $this->database->query('SELECT COUNT(*) FROM information_schema.tables');
        $count = $result->fetchColumn();

        if ($count < 0) {
            throw new \RuntimeException('Cannot read from database');
        }
    }

    public function checkDatabaseCanWrite(): void
    {
        $tempTable = '_health_check_' . uniqid();
        $this->database->exec("CREATE TEMPORARY TABLE {$tempTable} (id INT)");
        $this->database->exec("DROP TEMPORARY TABLE {$tempTable}");
    }

    public function checkDatabaseReplicationStatus(): void
    {
        try {
            $result = $this->database->query('SHOW SLAVE STATUS');

            if ($result->rowCount() > 0) {
                $status = $result->fetch(PDO::FETCH_ASSOC);

                if ($status['Slave_IO_Running'] !== 'Yes' || $status['Slave_SQL_Running'] !== 'Yes') {
                    throw new \RuntimeException('Database replication not running');
                }

                $secondsBehind = (int)$status['Seconds_Behind_Master'];

                if ($secondsBehind > 60) {
                    throw new \RuntimeException("Replication lag: {$secondsBehind}s");
                }
            }
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'replication')) {
                throw $e;
            }
        }
    }

    public function checkDatabaseConnectionPool(): void
    {
        $activeConnections = $this->database->query(
            'SELECT COUNT(*) FROM information_schema.processlist WHERE command != "Sleep"'
        )->fetchColumn();

        if ($activeConnections > 100) {
            throw new \RuntimeException("High connection count: {$activeConnections}");
        }
    }

    public function checkRedisConnection(): void
    {
        $this->redis->connect();
    }

    public function checkRedisPing(): void
    {
        $pong = $this->redis->ping();

        if ($pong !== 'PONG' && $pong !== true) {
            throw new \RuntimeException('Redis ping failed');
        }
    }

    public function checkRedisMemoryUsage(): void
    {
        $info = $this->redis->info('memory');
        $usedMemory = $info['used_memory_human'] ?? '0';

        $maxMemory = $this->redis->config('GET', 'maxmemory')['maxmemory'] ?? 0;

        if ($maxMemory > 0) {
            $usedBytes = $this->redis->info('memory')['used_memory'] ?? 0;
            $utilization = ($usedBytes / $maxMemory) * 100;

            if ($utilization > 90) {
                throw new \RuntimeException("Redis memory high: {$utilization}%");
            }
        }
    }

    public function checkRedisEvictionPolicy(): void
    {
        $policy = $this->redis->config('GET', 'maxmemory-policy')['maxmemory-policy'] ?? 'noeviction';

        $this->logger->debug("Redis eviction policy: {$policy}");
    }

    public function checkRabbitMqConnection(): void
    {
        $this->rabbitMq->connect();

        if (!$this->rabbitMq->isConnected()) {
            throw new \RuntimeException('RabbitMQ connection failed');
        }
    }

    public function checkRabbitMqQueues(): void
    {
        $queues = $this->rabbitMq->getQueues();

        foreach ($queues as $queue) {
            $messageCount = $queue['messages'] ?? 0;

            if ($messageCount > 10000) {
                $this->logger->warning("Queue {$queue['name']} has high message count: {$messageCount}");
            }
        }
    }

    public function checkRabbitMqMemoryUsage(): void
    {
        $memoryInfo = $this->rabbitMq->getMemoryUsage();

        if ($memoryInfo['used'] > $memoryInfo['limit'] * 0.9) {
            throw new \RuntimeException('RabbitMQ memory high');
        }
    }

    public function checkKafkaBrokers(): void
    {
        $brokers = $this->kafkaClient->getBrokers();

        if (empty($brokers)) {
            throw new \RuntimeException('No Kafka brokers available');
        }

        foreach ($brokers as $broker) {
            if (!$broker->isHealthy()) {
                throw new \RuntimeException("Kafka broker {$broker->getId()} unhealthy");
            }
        }
    }

    public function checkKafkaConsumerLags(): void
    {
        $consumerGroups = $this->kafkaClient->getConsumerGroups();

        foreach ($consumerGroups as $group) {
            $lag = $group->getTotalLag();

            if ($lag > 10000) {
                $this->logger->warning("Consumer group {$group->getId()} has high lag: {$lag}");
            }
        }
    }

    public function checkLocalDiskSpace(): void
    {
        $path = getcwd();
        $freeSpace = disk_free_space($path);
        $totalSpace = disk_total_space($path);

        $utilization = (($totalSpace - $freeSpace) / $totalSpace) * 100;

        if ($utilization > 90) {
            throw new \RuntimeException("Disk space critical: {$utilization}% used");
        }
    }

    public function checkNfsMounts(): void
    {
        $mounts = ['/mnt/nfs', '/mnt/shared'];

        foreach ($mounts as $mount) {
            if (is_dir($mount)) {
                $accessible = is_readable($mount) && is_writable($mount);

                if (!$accessible) {
                    throw new \RuntimeException("NFS mount {$mount} not accessible");
                }
            }
        }
    }

    public function checkS3Connection(): void
    {
        $buckets = $this->s3Client->listBuckets();

        $this->logger->debug("S3 accessible, found " . count($buckets) . " buckets");
    }

    public function checkFilePermissions(): void
    {
        $requiredPaths = [
            'storage' => 'writable',
            'bootstrap/cache' => 'writable',
            'public/uploads' => 'writable'
        ];

        foreach ($requiredPaths as $path => $permission) {
            $fullPath = getcwd() . '/' . $path;

            if (!file_exists($fullPath)) {
                throw new \RuntimeException("Required path missing: {$path}");
            }

            if ($permission === 'writable' && !is_writable($fullPath)) {
                throw new \RuntimeException("Required path not writable: {$path}");
            }
        }
    }

    public function checkPaymentGateway(): void
    {
        $health = $this->paymentGateway->checkHealth();

        if (!$health['healthy']) {
            throw new \RuntimeException('Payment gateway unhealthy');
        }
    }

    public function checkEmailService(): void
    {
        $health = $this->emailService->checkHealth();

        if (!$health['healthy']) {
            throw new \RuntimeException('Email service unhealthy');
        }
    }

    public function checkSmsService(): void
    {
        $health = $this->smsService->checkHealth();

        if (!$health['healthy']) {
            throw new \RuntimeException('SMS service unhealthy');
        }
    }

    public function checkCloudStorage(): void
    {
        $health = $this->cloudStorage->checkHealth();

        if (!$health['healthy']) {
            throw new \RuntimeException('Cloud storage unhealthy');
        }
    }
}
