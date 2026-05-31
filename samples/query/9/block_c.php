<?php
declare(strict_types=1);

namespace App\Reports\Geo;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class TechniciansByRegionReport
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function techniciansInRegion(string $regionCode): array
    {
        if ($regionCode === '') {
            throw new \InvalidArgumentException('regionCode is required');
        }

        $sql = 'SELECT t.id, t.full_name, t.last_seen_at,
                       ST_X(t.current_location::geometry) AS lon,
                       ST_Y(t.current_location::geometry) AS lat
                FROM technicians t
                JOIN regions r ON r.geom && t.current_location
                              AND ST_Contains(r.geom, t.current_location)
                WHERE r.code = :region_code
                  AND t.is_active = TRUE
                  AND t.deleted_at IS NULL
                ORDER BY t.last_seen_at DESC';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':region_code', $regionCode);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('Technicians-by-region query failed', [
                'region' => $regionCode,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to load technicians by region', 0, $e);
        }
    }
}
