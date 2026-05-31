<?php

declare(strict_types=1);

namespace App\Infrastructure;

class InfrastructureMetricsCollector
{
    private StatsDCollector $collector;
    private LoggerInterface $logger;

    public function __construct(StatsDCollector $collector, LoggerInterface $logger)
    {
        $this->collector = $collector;
        $this->logger = $logger;
    }

    public function recordContainerMetrics(
        string $containerId,
        string $containerName,
        float $cpuUsagePercent,
        float $memoryUsageMb,
        float $memoryLimitMb,
        int $networkRxBytes,
        int $networkTxBytes
    ): void {
        $baseLabels = [
            'container_id' => $containerId,
            'container_name' => $containerName
        ];

        $this->collector->gauge('container.cpu.usage.percent', $cpuUsagePercent, $baseLabels);

        $this->collector->gauge('container.memory.usage.mb', $memoryUsageMb, $baseLabels);

        $this->collector->gauge('container.memory.limit.mb', $memoryLimitMb, $baseLabels);

        $memoryUtilization = $memoryLimitMb > 0 ? ($memoryUsageMb / $memoryLimitMb) * 100 : 0;
        $this->collector->gauge('container.memory.utilization.percent', $memoryUtilization, $baseLabels);

        $this->collector->counter('container.network.rx.bytes', $networkRxBytes, $baseLabels);
        $this->collector->counter('container.network.tx.bytes', $networkTxBytes, $baseLabels);

        $this->checkContainerResourceThresholds(
            $containerName,
            $cpuUsagePercent,
            $memoryUtilization
        );

        if ($cpuUsagePercent > 90 || $memoryUtilization > 90) {
            $this->logger->warning('Container resource threshold exceeded', [
                'container' => $containerName,
                'cpu_percent' => $cpuUsagePercent,
                'memory_utilization_percent' => $memoryUtilization
            ]);
        }
    }

    private function checkContainerResourceThresholds(
        string $containerName,
        float $cpuUsage,
        float $memoryUtilization
    ): void {
        $cpuThreshold = 80;
        $memoryThreshold = 85;

        if ($cpuUsage > $cpuThreshold) {
            $this->collector->incrementCounter(
                'infrastructure.alerts',
                ['alert_type' => 'high_cpu', 'container' => $containerName]
            );
        }

        if ($memoryUtilization > $memoryThreshold) {
            $this->collector->incrementCounter(
                'infrastructure.alerts',
                ['alert_type' => 'high_memory', 'container' => $containerName]
            );
        }
    }

    public function recordDatabaseConnectionMetrics(
        string $connectionPool,
        int $activeConnections,
        int $idleConnections,
        int $maxConnections,
        float $connectionWaitTimeMs
    ): void {
        $labels = ['pool' => $connectionPool];

        $this->collector->gauge('database.connections.active', $activeConnections, $labels);
        $this->collector->gauge('database.connections.idle', $idleConnections, $labels);
        $this->collector->gauge('database.connections.max', $maxConnections, $labels);

        $utilization = $maxConnections > 0 ? ($activeConnections / $maxConnections) * 100 : 0;
        $this->collector->gauge('database.connections.utilization.percent', $utilization, $labels);

        $this->collector->histogram(
            'database.connections.wait_time.seconds',
            $connectionWaitTimeMs / 1000,
            $labels
        );

        if ($utilization > 80) {
            $this->collector->incrementCounter(
                'database.alerts',
                ['alert_type' => 'connection_pool_high', 'pool' => $connectionPool]
            );
        }

        if ($connectionWaitTimeMs > 1000) {
            $this->collector->incrementCounter(
                'database.alerts',
                ['alert_type' => 'connection_wait_slow', 'pool' => $connectionPool]
            );
        }
    }

    public function recordRedisMetrics(
        string $redisInstance,
        int $connectedClients,
        int $usedMemoryMb,
        float $hitRatePercent,
        int $opsPerSecond,
        int $blockedClients
    ): void {
        $labels = ['instance' => $redisInstance];

        $this->collector->gauge('redis.clients.connected', $connectedClients, $labels);
        $this->collector->gauge('redis.memory.used.mb', $usedMemoryMb, $labels);
        $this->collector->gauge('redis.hit_rate.percent', $hitRatePercent, $labels);
        $this->collector->gauge('redis.ops.per_second', $opsPerSecond, $labels);
        $this->collector->gauge('redis.clients.blocked', $blockedClients, $labels);

        if ($hitRatePercent < 80) {
            $this->collector->incrementCounter(
                'redis.alerts',
                ['alert_type' => 'low_hit_rate', 'instance' => $redisInstance]
            );
        }

        if ($usedMemoryMb > 1024) {
            $this->collector->incrementCounter(
                'redis.alerts',
                ['alert_type' => 'high_memory', 'instance' => $redisInstance]
            );
        }
    }

    public function recordMessageQueueMetrics(
        string $queueName,
        string $broker,
        int $messageCount,
        int $consumerCount,
        float $publishRate,
        float $consumeRate,
        float $averageLatencyMs
    ): void {
        $labels = ['queue' => $queueName, 'broker' => $broker];

        $this->collector->gauge('queue.messages.count', $messageCount, $labels);
        $this->collector->gauge('queue.consumers.count', $consumerCount, $labels);
        $this->collector->gauge('queue.publish.rate', $publishRate, $labels);
        $this->collector->gauge('queue.consume.rate', $consumeRate, $labels);
        $this->collector->histogram('queue.latency.seconds', $averageLatencyMs / 1000, $labels);

        $lagRatio = $consumerCount > 0 ? $messageCount / $consumerCount : 0;
        $this->collector->gauge('queue.consumer.lag.ratio', $lagRatio, $labels);

        if ($messageCount > 10000) {
            $this->collector->incrementCounter(
                'queue.alerts',
                ['alert_type' => 'queue_backlog', 'queue' => $queueName]
            );
        }

        if ($averageLatencyMs > 100) {
            $this->collector->incrementCounter(
                'queue.alerts',
                ['alert_type' => 'high_latency', 'queue' => $queueName]
            );
        }
    }

    public function recordLoadBalancerMetrics(
        string $lbName,
        int $activeConnections,
        float $requestsPerSecond,
        float $bytesInPerSecond,
        float $bytesOutPerSecond,
        float $avgLatencyMs
    ): void {
        $labels = ['load_balancer' => $lbName];

        $this->collector->gauge('lb.connections.active', $activeConnections, $labels);
        $this->collector->gauge('lb.requests.per_second', $requestsPerSecond, $labels);
        $this->collector->gauge('lb.bandwidth.in.bytes_per_second', $bytesInPerSecond, $labels);
        $this->collector->gauge('lb.bandwidth.out.bytes_per_second', $bytesOutPerSecond, $labels);
        $this->collector->histogram('lb.latency.seconds', $avgLatencyMs / 1000, $labels);

        if ($activeConnections > 10000) {
            $this->collector->incrementCounter(
                'lb.alerts',
                ['alert_type' => 'high_connections', 'load_balancer' => $lbName]
            );
        }
    }

    public function recordDnsQueryMetrics(
        string $resolver,
        float $queryLatencyMs,
        int $successCount,
        int $failureCount,
        string $recordType = 'A'
    ): void {
        $labels = ['resolver' => $resolver, 'record_type' => $recordType];

        $this->collector->histogram('dns.query.latency.seconds', $queryLatencyMs / 1000, $labels);
        $this->collector->counter('dns.query.success', $successCount, $labels);
        $this->collector->counter('dns.query.failure', $failureCount, $labels);

        $total = $successCount + $failureCount;
        if ($total > 0) {
            $successRate = ($successCount / $total) * 100;
            $this->collector->gauge('dns.query.success_rate.percent', $successRate, $labels);
        }

        if ($queryLatencyMs > 100) {
            $this->collector->incrementCounter(
                'dns.alerts',
                ['alert_type' => 'slow_query', 'resolver' => $resolver]
            );
        }
    }

    public function recordSslCertificateMetrics(
        string $domain,
        int $daysUntilExpiry,
        string $issuer,
        string $algorithm
    ): void {
        $labels = ['domain' => $domain, 'issuer' => $issuer, 'algorithm' => $algorithm];

        $this->collector->gauge('ssl.certificate.days_until_expiry', $daysUntilExpiry, $labels);

        if ($daysUntilExpiry < 30) {
            $this->collector->incrementCounter(
                'ssl.alerts',
                ['alert_type' => 'certificate_expiring', 'domain' => $domain]
            );
        }

        if ($daysUntilExpiry < 7) {
            $this->collector->incrementCounter(
                'ssl.alerts',
                ['alert_type' => 'certificate_critical', 'domain' => $domain]
            );
        }
    }

    public function recordDiskIoMetrics(
        string $device,
        float $readBytesPerSecond,
        float $writeBytesPerSecond,
        float $readLatencyMs,
        float $writeLatencyMs,
        int $ioUtilizationPercent
    ): void {
        $labels = ['device' => $device];

        $this->collector->gauge('disk.io.read.bytes_per_second', $readBytesPerSecond, $labels);
        $this->collector->gauge('disk.io.write.bytes_per_second', $writeBytesPerSecond, $labels);
        $this->collector->gauge('disk.io.read.latency.seconds', $readLatencyMs / 1000, $labels);
        $this->collector->gauge('disk.io.write.latency.seconds', $writeLatencyMs / 1000, $labels);
        $this->collector->gauge('disk.io.utilization.percent', $ioUtilizationPercent, $labels);

        if ($ioUtilizationPercent > 80) {
            $this->collector->incrementCounter(
                'disk.alerts',
                ['alert_type' => 'high_io_utilization', 'device' => $device]
            );
        }
    }
}
