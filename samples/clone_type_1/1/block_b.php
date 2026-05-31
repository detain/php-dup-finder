<?php
declare(strict_types=1);

namespace Acme\Import\Vendors;

final class VendorCsvImporter
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
      // strip newline characters
      $value = str_replace(["\r", "\n"], ' ', $value);
      // collapse whitespace runs
      $value = preg_replace('/\s+/', ' ', $value);
      $record['col_' . $i] = $value;
    }
    if (count($record) === 0) {
      return [];
    }
    $record['__hash'] = md5(implode('|', $record));
    return $record;
  }

  public function lookupVendor(string $hash): ?int
  {
    return null;
  }
}
