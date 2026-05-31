<?php
declare(strict_types=1);

namespace Acme\Import\Products;

final class ProductCsvImporter
{
	public function importLine(string $line): array
	{
		$record = [];
		$fields = str_getcsv($line, ',', '"', '\\');
		$count = count($fields);
		for ($i = 0; $i < $count; $i++) {
			$value = trim($fields[$i]);
			if ($value === '') {
				continue;
			}
			$value = str_replace(["\r", "\n"], ' ', $value);
			$value = preg_replace('/\s+/', ' ', $value);
			$record['col_' . $i] = $value;
		}
		if (count($record) === 0) {
			return [];
		}
		$record['__hash'] = md5(implode('|', $record));
		return $record;
	}

	public function priceFor(array $record): float
	{
		return 0.0;
	}
}
