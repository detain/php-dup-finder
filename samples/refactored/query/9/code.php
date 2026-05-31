<?php
declare(strict_types=1);

namespace App\Reports\Geo;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class RegionContainmentQuery
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param list<string>                              $columns       e.g. ['s.id', 's.name', 's.opened_at']
     * @param array<string, scalar|\DateTimeInterface>  $extraBindings keyed by ':name'
     * @return array<int, array<string, mixed>>
     */
    public function entitiesInRegion(
        string $table,
        string $alias,
        string $locationColumn,
        array $columns,
        string $regionCode,
        string $extraWhere = '',
        array $extraBindings = [],
        string $orderBy = ''
    ): array {
        if ($regionCode === '') {
            throw new \InvalidArgumentException('regionCode is required');
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException('Invalid table');
        }

        $select = implode(', ', $columns);
        $where = "WHERE r.code = :region_code AND {$alias}.deleted_at IS NULL"
               . ($extraWhere !== '' ? " AND ({$extraWhere})" : '');
        $order = $orderBy !== '' ? "ORDER BY {$orderBy}" : '';

        $sql = "SELECT {$select},
                       ST_X({$alias}.{$locationColumn}::geometry) AS lon,
                       ST_Y({$alias}.{$locationColumn}::geometry) AS lat
                FROM {$table} {$alias}
                JOIN regions r ON r.geom && {$alias}.{$locationColumn}
                              AND ST_Contains(r.geom, {$alias}.{$locationColumn})
                {$where}
                {$order}";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':region_code', $regionCode);
            foreach ($extraBindings as $name => $value) {
                $stmt->bindValue($name, $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('Region containment query failed', [
                'table' => $table,
                'region' => $regionCode,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Unable to load {$table} for region", 0, $e);
        }
    }
}
