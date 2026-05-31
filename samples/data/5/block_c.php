<?php
declare(strict_types=1);

namespace App\Inventory\Barcodes;

function generate_barcodes_for_products(\PDO $pdo, string $outputDir): int
{
    if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true)) {
        throw new \RuntimeException("Cannot create output dir: {$outputDir}");
    }

    $countStmt = $pdo->query('SELECT COUNT(*) FROM products WHERE active = 1 AND barcode_image IS NULL');
    $remaining = (int)$countStmt->fetchColumn();

    if ($remaining === 0) {
        error_log('No products need barcode generation');
        return 0;
    }

    $generated = 0;

    while ($remaining > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, sku, ean13 FROM products
             WHERE active = 1 AND barcode_image IS NULL
             ORDER BY id
             LIMIT 500'
        );
        $stmt->execute();
        $batch = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($batch === []) {
            break;
        }

        foreach ($batch as $product) {
            $code = (string)$product['ean13'];
            if ($code === '' || strlen($code) !== 13) {
                error_log("Invalid EAN13 for product {$product['id']}: {$code}");
                continue;
            }

            $path = $outputDir . '/' . $product['sku'] . '.png';
            $png = render_ean13_png($code);
            if (file_put_contents($path, $png) === false) {
                throw new \RuntimeException("Could not write barcode for SKU {$product['sku']}");
            }

            $pdo->prepare('UPDATE products SET barcode_image = ?, barcoded_at = NOW() WHERE id = ?')
                ->execute([$path, (int)$product['id']]);

            $generated++;
        }

        $remaining -= 500;
    }

    return $generated;
}

function render_ean13_png(string $code): string
{
    return 'PNGFAKE:' . $code;
}
