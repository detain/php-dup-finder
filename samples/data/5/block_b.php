<?php
declare(strict_types=1);

namespace App\Inventory\Import;

function import_supplier_catalog(\PDO $pdo, string $csvPath, int $supplierId): int
{
    if (!is_readable($csvPath)) {
        throw new \RuntimeException("Cannot read catalog: {$csvPath}");
    }

    $handle = fopen($csvPath, 'rb');
    if ($handle === false) {
        throw new \RuntimeException("Failed to open catalog");
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        throw new \RuntimeException("Empty catalog file");
    }

    $required = ['sku', 'name', 'wholesale_price', 'msrp', 'unit'];
    foreach ($required as $col) {
        if (!in_array($col, $header, true)) {
            fclose($handle);
            throw new \RuntimeException("Missing required column: {$col}");
        }
    }

    $imported = 0;
    $batch = [];

    while (($row = fgetcsv($handle)) !== false) {
        $record = array_combine($header, $row);
        if ($record === false || empty($record['sku'])) {
            continue;
        }

        $batch[] = [
            'sku'       => $record['sku'],
            'name'      => $record['name'],
            'wholesale' => (float)$record['wholesale_price'],
            'msrp'      => (float)$record['msrp'],
            'unit'      => $record['unit'],
        ];

        if (count($batch) >= 500) {
            $pdo->beginTransaction();
            try {
                foreach ($batch as $b) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO supplier_products (supplier_id, sku, name, wholesale_cents, msrp_cents, unit)
                         VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            name = VALUES(name),
                            wholesale_cents = VALUES(wholesale_cents),
                            msrp_cents = VALUES(msrp_cents)'
                    );
                    $stmt->execute([
                        $supplierId, $b['sku'], $b['name'],
                        (int)round($b['wholesale'] * 100), (int)round($b['msrp'] * 100), $b['unit'],
                    ]);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                fclose($handle);
                throw $e;
            }

            $imported += count($batch);
            $batch = [];
        }
    }

    fclose($handle);
    return $imported;
}
