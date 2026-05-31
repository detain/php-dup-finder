<?php
declare(strict_types=1);

namespace Acme\Contacts\Import;

use Acme\Contacts\Domain\CsvRow;
use Acme\Contacts\Domain\Contact;

final class ContactImportTransformer
{
    public function __construct(
        private readonly string $defaultCountry,
    ) {
    }

    /**
     * @param array<int, CsvRow> $rows
     * @return array<int, Contact>
     */
    public function import(array $rows): array
    {
        // identical token-shape: map then filter then values
        return array_values(array_filter(array_map(
            function (CsvRow $row): ?Contact {
                if (trim($row->email()) === '') {
                    return null;
                }
                return new Contact(
                    $row->id(),
                    trim($row->name()),
                    strtolower(trim($row->email())),
                    $this->defaultCountry,
                );
            },
            $rows,
        ), static fn (?Contact $c): bool => $c !== null));
    }

    /**
     * @param array<int, CsvRow> $rows
     */
    public function importableCount(array $rows): int
    {
        return count($this->import($rows));
    }
}
