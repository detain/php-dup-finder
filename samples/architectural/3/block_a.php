<?php
declare(strict_types=1);

namespace App\Reads\Dashboard;

final class DashboardMetricsQuery
{
    public function __construct(public string $tenantId, public \DateTimeImmutable $since) {}
}

final class DashboardMetricsProjection
{
    public function __construct(public int $activeUsers, public int $sessions, public float $avgLatencyMs) {}
}

final class DashboardMetricsReader
{
    public function __construct(private \PDO $pdo) {}

    public function read(DashboardMetricsQuery $q): DashboardMetricsProjection
    {
        $sql = 'SELECT COUNT(DISTINCT user_id) AS u, COUNT(*) AS s, AVG(latency_ms) AS l
                FROM events WHERE tenant = ? AND created_at >= ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$q->tenantId, $q->since->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['u' => 0, 's' => 0, 'l' => 0.0];
        return new DashboardMetricsProjection((int) $row['u'], (int) $row['s'], (float) $row['l']);
    }
}

final class DashboardQueryHandler
{
    private array $cache = [];

    public function __construct(private DashboardMetricsReader $reader) {}

    public function handle(DashboardMetricsQuery $q): DashboardMetricsProjection
    {
        $key = $q->tenantId . '|' . $q->since->format('U');
        return $this->cache[$key] ??= $this->reader->read($q);
    }
}
