<?php
declare(strict_types=1);

namespace Acme\Export\Invoice;

use Acme\Billing\InvoiceQueryService;
use Acme\Export\S3Uploader;
use Acme\Export\ExportSummary;
use Acme\Export\Exceptions\ExportFailure;

final class InvoiceExporter
{
    public function __construct(
        private readonly InvoiceQueryService $invoices,
        private readonly S3Uploader $uploader,
        private readonly string $bucket
    ) {
    }

    public function export(\DateTimeImmutable $since): ExportSummary
    {
        $tmp = tempnam(sys_get_temp_dir(), 'invoices-');
        if ($tmp === false) {
            throw new ExportFailure('temp file');
        }
        $fh = fopen($tmp, 'w');
        fputcsv($fh, ['invoice_id', 'customer_id', 'issued_at', 'amount_due']);

        $offset = 0;
        $batch  = 500;
        $count  = 0;
        while (true) {
            $chunk = $this->invoices->page($since, $offset, $batch);
            if ($chunk === []) {
                break;
            }
            foreach ($chunk as $inv) {
                fputcsv($fh, [
                    $inv->id,
                    $inv->customerId,
                    $inv->issuedAt->format(DATE_ATOM),
                    number_format($inv->amountDue, 2, '.', ''),
                ]);
                $count++;
            }
            $offset += $batch;
        }
        fclose($fh);

        $gz = $tmp . '.gz';
        $in  = fopen($tmp, 'rb');
        $out = gzopen($gz, 'wb9');
        while (!feof($in)) {
            gzwrite($out, (string)fread($in, 8192));
        }
        fclose($in);
        gzclose($out);
        unlink($tmp);

        $key = sprintf('exports/invoices/%s.csv.gz', $since->format('Ymd-His'));
        $this->uploader->upload($this->bucket, $key, $gz);
        unlink($gz);

        return new ExportSummary('invoices', $count, $key);
    }
}
