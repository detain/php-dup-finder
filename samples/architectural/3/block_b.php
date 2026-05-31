<?php
declare(strict_types=1);

namespace App\Reads\Sales;

final class SalesReportQuery
{
    public function __construct(public string $region, public \DateTimeImmutable $from) {}
}

final class SalesReportProjection
{
    public function __construct(public int $orders, public int $revenueCents, public float $avgOrderCents) {}
}

final class SalesReportReader
{
    public function __construct(private \PDO $pdo) {}

    public function read(SalesReportQuery $q): SalesReportProjection
    {
        $sql = 'SELECT COUNT(*) AS o, COALESCE(SUM(total_cents),0) AS r, COALESCE(AVG(total_cents),0) AS a
                FROM orders WHERE region = ? AND placed_at >= ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$q->region, $q->from->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['o' => 0, 'r' => 0, 'a' => 0.0];
        return new SalesReportProjection((int) $row['o'], (int) $row['r'], (float) $row['a']);
    }
}

final class SalesQueryHandler
{
    private array $cache = [];

    public function __construct(private SalesReportReader $reader) {}

    public function handle(SalesReportQuery $q): SalesReportProjection
    {
        $key = $q->region . '|' . $q->from->format('U');
        return $this->cache[$key] ??= $this->reader->read($q);
    }
}
