<?php
declare(strict_types=1);

namespace Acme\Csv;

final class CsvRecordEmitter
{
    public function __construct(
        private CsvReader $reader,
        private NormalizerInterface $normalizer,
    ) {
    }

    /**
     * @return \Generator<int, array{0:string, 1:array<string,string>}>
     */
    public function emit(string $path): \Generator
    {
        foreach ($this->reader->rows($path) as $rowIndex => $row) {
            if (isset($row['__expanded'])) {
                yield from $this->expandSubRows($rowIndex, $row);
            } else {
                yield [
                    sprintf('row-%d', $rowIndex),
                    $this->normalizer->normalize($row),
                ];
            }
        }
    }

    /**
     * @return \Generator<int, array{0:string, 1:array<string,string>}>
     */
    private function expandSubRows(int $rowIndex, array $row): \Generator
    {
        foreach ($row['__expanded'] as $subIndex => $sub) {
            yield [
                sprintf('row-%d.%d', $rowIndex, $subIndex),
                $this->normalizer->normalize($sub),
            ];
        }
    }
}
