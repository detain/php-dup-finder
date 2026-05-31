<?php

declare(strict_types=1);

namespace App\Monitoring;

class SystemHealthEndpoint
{
    private DatabaseHealthCheck $dbCheck;
    private CacheHealthCheck $cacheCheck;
    private QueueHealthCheck $queueCheck;
    private LoggerInterface $logger;

    public function __construct(
        DatabaseHealthCheck $dbCheck,
        CacheHealthCheck $cacheCheck,
        QueueHealthCheck $queueCheck,
        LoggerInterface $logger
    ) {
        $this->dbCheck = $dbCheck;
        $this->cacheCheck = $cacheCheck;
        $this->queueCheck = $queueCheck;
        $this->logger = $logger;
    }

    public function getLivenessStatus(): array
    {
        return [
            'status' => 'alive',
            'timestamp' => date('c'),
            'uptime' => $this->getApplicationUptime()
        ];
    }

    public function getReadinessStatus(): array
    {
        $checks = [];
        $allHealthy = true;

        try {
            $dbStatus = $this->dbCheck->verifyConnection();
            $checks['database'] = $dbStatus;
            if (!$dbStatus['ready']) {
                $allHealthy = false;
            }
        } catch (\Exception $e) {
            $checks['database'] = ['ready' => false, 'error' => $e->getMessage()];
            $allHealthy = false;
        }

        try {
            $cacheStatus = $this->cacheCheck->verifyConnection();
            $checks['cache'] = $cacheStatus;
            if (!$cacheStatus['ready']) {
                $allHealthy = false;
            }
        } catch (\Exception $e) {
            $checks['cache'] = ['ready' => false, 'error' => $e->getMessage()];
            $allHealthy = false;
        }

        try {
            $queueStatus = $this->queueCheck->verifyConnection();
            $checks['queue'] = $queueStatus;
            if (!$queueStatus['ready']) {
                $allHealthy = false;
            }
        } catch (\Exception $e) {
            $checks['queue'] = ['ready' => false, 'error' => $e->getMessage()];
            $allHealthy = false;
        }

        return [
            'ready' => $allHealthy,
            'checks' => $checks,
            'timestamp' => date('c')
        ];
    }

    public function getDetailedHealth(): array
    {
        return [
            'liveness' => $this->getLivenessStatus(),
            'readiness' => $this->getReadinessStatus(),
            'components' => $this->collectComponentHealth(),
            'metrics' => $this->collectHealthMetrics()
        ];
    }

    private function collectComponentHealth(): array
    {
        $components = [];

        $components['database'] = $this->dbCheck->collectDetailedStatus();
        $components['cache'] = $this->cacheCheck->collectDetailedStatus();
        $components['queue'] = $this->queueCheck->collectDetailedStatus();

        return $components;
    }

    private function collectHealthMetrics(): array
    {
        return [
            'memory_usage' => $this->getMemoryUsage(),
            'cpu_usage' => $this->getCpuUsage(),
            'disk_usage' => $this->getDiskUsage(),
            'network_stats' => $this->getNetworkStats()
        ];
    }

    private function getApplicationUptime(): int
    {
        $startTime = defined('APP_START_TIME') ? APP_START_TIME : time();

        return time() - $startTime;
    }

    private function getMemoryUsage(): array
    {
        $mem = memory_get_usage(true);

        return [
            'used_bytes' => $mem,
            'used_mb' => round($mem / 1024 / 1024, 2),
            'peak_bytes' => memory_get_peak_usage(true)
        ];
    }

    private function getCpuUsage(): array
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();

            return [
                'load_1m' => $load[0],
                'load_5m' => $load[1],
                'load_15m' => $load[2]
            ];
        }

        return ['load_1m' => 0, 'load_5m' => 0, 'load_15m' => 0];
    }

    private function getDiskUsage(): array
    {
        $path = getcwd();
        $free = disk_free_space($path);
        $total = disk_total_space($path);

        return [
            'free_bytes' => $free,
            'total_bytes' => $total,
            'free_gb' => round($free / 1024 / 1024 / 1024, 2),
            'utilization_percent' => round((($total - $free) / $total) * 100, 2)
        ];
    }

    private function getNetworkStats(): array
    {
        return [
            'connections' => $this->countConnections(),
            'requests_total' => $this->getTotalRequests()
        ];
    }

    private function countConnections(): int
    {
        $command = "netstat -an 2>/dev/null | grep ESTABLISHED | wc -l";

        return (int)shell_exec($command);
    }

    private function getTotalRequests(): int
    {
        return defined('TOTAL_REQUESTS') ? TOTAL_REQUESTS : 0;
    }
}

interface HealthCheckInterface
{
    public function verifyConnection(): array;
    public function collectDetailedStatus(): array;
}

class DatabaseHealthCheck implements HealthCheckInterface
{
    private ConnectionPool $pool;
    private LoggerInterface $logger;

    public function verifyConnection(): array
    {
        try {
            $start = microtime(true);
            $conn = $this->pool->getConnection();
            $duration = (microtime(true) - $start) * 1000;

            $conn->query('SELECT 1');
            $this->pool->release($conn);

            return [
                'ready' => true,
                'latency_ms' => round($duration, 2)
            ];
        } catch (\Exception $e) {
            return [
                'ready' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function collectDetailedStatus(): array
    {
        $basic = $this->verifyConnection();

        $details = [
            'connection_pool_size' => $this->pool->getSize(),
            'connection_pool_used' => $this->pool->getUsedConnections(),
            'connection_pool_idle' => $this->pool->getIdleConnections()
        ];

        try {
            $tablesCount = $this->pool->getConnection()
                ->query('SELECT COUNT(*) FROM information_schema.tables')
                ->fetchColumn();

            $details['tables_count'] = $tablesCount;
        } catch (\Exception $e) {
            $details['tables_count'] = 0;
        }

        return array_merge($basic, $details);
    }
}

class CacheHealthCheck implements HealthCheckInterface
{
    private RedisCluster $redis;
    private LoggerInterface $logger;

    public function verifyConnection(): array
    {
        try {
            $start = microtime(true);
            $this->redis->ping();
            $duration = (microtime(true) - $start) * 1000;

            return [
                'ready' => true,
                'latency_ms' => round($duration, 2)
            ];
        } catch (\Exception $e) {
            return [
                'ready' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function collectDetailedStatus(): array
    {
        $basic = $this->verifyConnection();

        try {
            $info = $this->redis->info('memory');
            $details = [
                'memory_used' => $info['used_memory_human'] ?? 'unknown',
                'memory_peak' => $info['used_memory_peak_human'] ?? 'unknown'
            ];
        } catch (\Exception $e) {
            $details = ['memory_used' => 'unknown'];
        }

        return array_merge($basic, $details);
    }
}

class QueueHealthCheck implements HealthCheckInterface
{
    private RabbitMQConnection $mq;
    private LoggerInterface $logger;

    public function verifyConnection(): array
    {
        try {
            $start = microtime(true);
            $this->mq->connect();
            $duration = (microtime(true) - $start) * 1000;

            return [
                'ready' => true,
                'latency_ms' => round($duration, 2)
            ];
        } catch (\Exception $e) {
            return [
                'ready' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function collectDetailedStatus(): array
    {
        $basic = $this->verifyConnection();

        try {
            $queues = $this->mq->getQueueInfo();
            $details = [
                'queues' => count($queues),
                'total_messages' => array_sum(array_column($queues, 'messages'))
            ];
        } catch (\Exception $e) {
            $details = ['queues' => 0];
        }

        return array_merge($basic, $details);
    }
}
