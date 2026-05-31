<?php
declare(strict_types=1);

namespace Acme\Catalog\Import;

final class NativeCsvImporter
{
    /** @param resource $handle */
    public function importRow($handle): ?Product
    {
        if (!is_resource($handle)) {
            throw new \InvalidArgumentException('handle must be a resource');
        }
        $row = fgetcsv($handle, 0, ',', '"', '\\');
        if ($row === false || $row === [null]) {
            return null;
        }
        if (count($row) < 6) {
            throw new \DomainException('insufficient columns');
        }
        [$sku, $name, $priceRaw, $stockRaw, $taxableRaw, $launchRaw] = array_pad($row, 6, '');
        $sku  = strtoupper(trim((string) $sku));
        $name = trim((string) $name);
        if ($sku === '' || $name === '') {
            throw new \DomainException('sku and name required');
        }
        if (!preg_match('/^[A-Z0-9\-]{3,32}$/', $sku)) {
            throw new \DomainException("bad sku: $sku");
        }
        $priceCents = (int) round(((float) str_replace(['$', ','], ['', ''], (string) $priceRaw)) * 100);
        if ($priceCents < 0) {
            throw new \DomainException('negative price');
        }
        $stock = (int) trim((string) $stockRaw);
        if ($stock < 0) {
            throw new \DomainException('negative stock');
        }
        $taxable = in_array(strtolower(trim((string) $taxableRaw)), ['1', 'true', 'yes', 'y'], true);
        $launchRaw = trim((string) $launchRaw);
        $launch = $launchRaw === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $launchRaw);
        if ($launch === false) {
            throw new \DomainException("bad date: $launchRaw");
        }
        return new Product($sku, $name, $priceCents, $stock, $taxable, $launch);
    }
}

final class Product
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly int $priceCents,
        public readonly int $stock,
        public readonly bool $taxable,
        public readonly ?\DateTimeImmutable $launchDate,
    ) {}
}
