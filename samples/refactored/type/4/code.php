<?php
declare(strict_types=1);

namespace Acme\Export;

use Acme\Export\Exceptions\ExportFailure;

interface ChunkSource
{
    public function entityName(): string;
    /** @return array<string> */
    public function columns(): array;
    /** @return iterable<object> */
    public function page(\DateTimeImmutable $since, int $offset, int $size): iterable;
    /** @return array<scalar> */
    public function project(object $row): array;
}

final class StreamingExporter
{
    public function __construct(
        private readonly S3Uploader $uploader,
        private readonly string $bucket,
        private readonly int $batchSize = 500
    ) {
    }

    public function export(ChunkSource $source, \DateTimeImmutable $since): ExportSummary
    {
        $tmp = tempnam(sys_get_temp_dir(), $source->entityName() . '-');
        if ($tmp === false) {
            throw new ExportFailure('temp file');
        }
        $fh = fopen($tmp, 'w');
        fputcsv($fh, $source->columns());

        $offset = 0; $count = 0;
        while (true) {
            $chunk = $source->page($since, $offset, $this->batchSize);
            $empty = true;
            foreach ($chunk as $row) {
                fputcsv($fh, $source->project($row));
                $count++;
                $empty = false;
            }
            if ($empty) {
                break;
            }
            $offset += $this->batchSize;
        }
        fclose($fh);

        $gz = $tmp . '.gz';
        $in  = fopen($tmp, 'rb');
        $out = gzopen($gz, 'wb9');
        while (!feof($in)) {
            gzwrite($out, (string)fread($in, 8192));
        }
        fclose($in); gzclose($out); unlink($tmp);

        $key = sprintf('exports/%s/%s.csv.gz', $source->entityName(), $since->format('Ymd-His'));
        $this->uploader->upload($this->bucket, $key, $gz);
        unlink($gz);

        return new ExportSummary($source->entityName(), $count, $key);
    }
}
