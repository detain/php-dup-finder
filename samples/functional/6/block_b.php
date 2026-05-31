<?php
declare(strict_types=1);

namespace Acme\Catalog\Import;

use League\Csv\Reader;
use League\Csv\Statement;

final class LeagueCsvImporter
{
    public function load(string $path): \Generator
    {
        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);
        $stmt = Statement::create();
        $records = $stmt->process($reader);
        foreach ($records as $record) {
            yield $this->mapRecord($record);
        }
    }

    /** @param array<string,string|null> $record */
    private function mapRecord(array $record): Product
    {
        $sku = strtoupper(trim((string) ($record['sku'] ?? '')));
        if (!preg_match('/^[A-Z0-9\-]{3,32}$/', $sku)) {
            throw new \DomainException("invalid sku: $sku");
        }
        $name = trim((string) ($record['name'] ?? ''));
        if ($name === '') {
            throw new \DomainException('missing name');
        }
        $priceRaw = (string) ($record['price'] ?? '0');
        $priceFloat = (float) str_replace(['$', ',', ' '], '', $priceRaw);
        $priceCents = (int) round($priceFloat * 100);
        if ($priceCents < 0) {
            throw new \DomainException('price negative');
        }
        $stock = (int) ($record['stock'] ?? 0);
        if ($stock < 0) {
            throw new \DomainException('stock negative');
        }
        $taxable = filter_var($record['taxable'] ?? '', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
        $launchValue = trim((string) ($record['launch_date'] ?? ''));
        $launch = $launchValue === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $launchValue);
        if ($launch === false) {
            throw new \DomainException("bad launch_date: $launchValue");
        }
        return new Product($sku, $name, $priceCents, $stock, $taxable, $launch);
    }
}
