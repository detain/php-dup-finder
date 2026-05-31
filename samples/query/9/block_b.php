<?php
declare(strict_types=1);

namespace App\Reports\Geo;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class DeliveriesByRegionReport
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deliveriesInRegion(string $regionCode, \DateTimeImmutable $since): array
    {
        if ($regionCode === '') {
            throw new \InvalidArgumentException('regionCode is required');
        }

        $sql = 'SELECT d.id, d.tracking_number, d.delivered_at,
                       ST_X(d.drop_location::geometry) AS lon,
                       ST_Y(d.drop_location::geometry) AS lat
                FROM deliveries d
                JOIN regions r ON r.geom && d.drop_location
                              AND ST_Contains(r.geom, d.drop_location)
                WHERE r.code = :region_code
                  AND d.delivered_at >= :since
                  AND d.deleted_at IS NULL
                ORDER BY d.delivered_at DESC';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':region_code', $regionCode);
            $stmt->bindValue(':since', $since->format('Y-m-d H:i:s'));
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('Deliveries-by-region query failed', [
                'region' => $regionCode,
                'since' => $since->format(DATE_ATOM),
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to load deliveries by region', 0, $e);
        }
    }
}
