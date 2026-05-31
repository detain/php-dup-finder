<?php
declare(strict_types=1);

namespace App\Reports\Geo;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class StoresByRegionReport
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function storesInRegion(string $regionCode): array
    {
        if ($regionCode === '') {
            throw new \InvalidArgumentException('regionCode is required');
        }

        $sql = 'SELECT s.id, s.name, s.opened_at,
                       ST_X(s.location::geometry) AS lon,
                       ST_Y(s.location::geometry) AS lat
                FROM stores s
                JOIN regions r ON r.geom && s.location
                              AND ST_Contains(r.geom, s.location)
                WHERE r.code = :region_code
                  AND s.deleted_at IS NULL
                ORDER BY s.opened_at DESC';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':region_code', $regionCode);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('Stores-by-region query failed', [
                'region' => $regionCode,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to load stores by region', 0, $e);
        }
    }
}
