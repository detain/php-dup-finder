<?php
declare(strict_types=1);

namespace App\Inventory;

final class BatchConfig
{
    public const DEFAULT_BATCH_SIZE = 500;
}

namespace App\Inventory\Reconcile;

use App\Inventory\BatchConfig;

function reconcile_warehouse_stock(\PDO $pdo, int $warehouseId): array
{
    $processed = 0;
    $offset = 0;
    while (true) {
        $stmt = $pdo->prepare(
            'SELECT id, sku, on_hand FROM warehouse_stock WHERE warehouse_id = ? ORDER BY id LIMIT ? OFFSET ?'
        );
        $stmt->execute([$warehouseId, BatchConfig::DEFAULT_BATCH_SIZE, $offset]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }
        $processed += count($rows);
        $offset += BatchConfig::DEFAULT_BATCH_SIZE;
    }
    return ['processed' => $processed];
}

namespace App\Inventory\Import;

use App\Inventory\BatchConfig;

function import_supplier_catalog(\PDO $pdo, string $csvPath, int $supplierId): int
{
    $handle = fopen($csvPath, 'rb');
    $imported = 0;
    $batch = [];
    while (($row = fgetcsv($handle)) !== false) {
        $batch[] = $row;
        if (count($batch) >= BatchConfig::DEFAULT_BATCH_SIZE) {
            $imported += count($batch);
            $batch = [];
        }
    }
    fclose($handle);
    return $imported;
}

namespace App\Inventory\Barcodes;

use App\Inventory\BatchConfig;

function generate_barcodes_for_products(\PDO $pdo): int
{
    $stmt = $pdo->prepare(
        'SELECT id, sku FROM products WHERE active = 1 AND barcode_image IS NULL LIMIT ?'
    );
    $stmt->execute([BatchConfig::DEFAULT_BATCH_SIZE]);
    return $stmt->rowCount();
}
