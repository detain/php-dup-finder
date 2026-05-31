<?php
declare(strict_types=1);

namespace Acme\Catalog\Import;

final class ProductRowMapper
{
    /** @param array<string,string|null> $row */
    public function toProduct(array $row): Product
    {
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        if (!preg_match('/^[A-Z0-9\-]{3,32}$/', $sku)) {
            throw new \DomainException("invalid sku: $sku");
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw new \DomainException('missing name');
        }
        $priceRaw   = (string) ($row['price'] ?? '0');
        $priceCents = (int) round(((float) preg_replace('/[^0-9.\-]/', '', $priceRaw)) * 100);
        $stock      = (int) ($row['stock'] ?? 0);
        if ($priceCents < 0 || $stock < 0) {
            throw new \DomainException('negative numeric');
        }
        $taxable = in_array(strtolower(trim((string) ($row['taxable'] ?? ''))), ['1','true','yes','y'], true);
        $launchRaw = trim((string) ($row['launch_date'] ?? ''));
        $launch    = $launchRaw === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $launchRaw);
        if ($launch === false) {
            throw new \DomainException("bad date: $launchRaw");
        }
        return new Product($sku, $name, $priceCents, $stock, $taxable, $launch);
    }
}
