<?php
declare(strict_types=1);

namespace Acme\Catalog\Import;

use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

final class SerializerImporter
{
    private Serializer $serializer;

    public function __construct()
    {
        $this->serializer = new Serializer(
            [new ObjectNormalizer()],
            [new CsvEncoder()],
        );
    }

    public function fromCsvLine(string $headerLine, string $dataLine): Product
    {
        $payload = $headerLine . "\n" . $dataLine;
        $decoded = $this->serializer->decode($payload, 'csv');
        if (!is_array($decoded) || $decoded === []) {
            throw new \DomainException('empty csv');
        }
        $row = $decoded[0] ?? $decoded;
        if (!is_array($row)) {
            throw new \DomainException('row not associative');
        }
        $sku  = strtoupper(trim((string) ($row['sku'] ?? '')));
        $name = trim((string) ($row['name'] ?? ''));
        if (!preg_match('/^[A-Z0-9\-]{3,32}$/', $sku) || $name === '') {
            throw new \DomainException('sku/name invalid');
        }
        $priceCents = (int) round(((float) preg_replace('/[^0-9.\-]/', '', (string) ($row['price'] ?? '0'))) * 100);
        $stock      = (int) ($row['stock'] ?? 0);
        if ($priceCents < 0 || $stock < 0) {
            throw new \DomainException('negative numeric');
        }
        $taxable = in_array(strtolower(trim((string) ($row['taxable'] ?? ''))), ['1','true','yes','y'], true);
        $launchRaw = trim((string) ($row['launch_date'] ?? ''));
        $launch = $launchRaw === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $launchRaw);
        if ($launch === false) {
            throw new \DomainException("bad date: $launchRaw");
        }
        return new Product($sku, $name, $priceCents, $stock, $taxable, $launch);
    }
}
