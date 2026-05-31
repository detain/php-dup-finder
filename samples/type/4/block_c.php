<?php
declare(strict_types=1);

namespace Acme\Export\Shipment;

use Acme\Logistics\ShipmentQueryService;
use Acme\Export\S3Uploader;
use Acme\Export\ExportSummary;
use Acme\Export\Exceptions\ExportFailure;

final class ShipmentExporter
{
    public function __construct(
        private readonly ShipmentQueryService $shipments,
        private readonly S3Uploader $uploader,
        private readonly string $bucket
    ) {
    }

    public function export(\DateTimeImmutable $since): ExportSummary
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shipments-');
        if ($tmp === false) {
            throw new ExportFailure('temp file');
        }
        $fh = fopen($tmp, 'w');
        fputcsv($fh, ['shipment_id', 'order_id', 'dispatched_at', 'carrier']);

        $offset = 0;
        $batch  = 500;
        $count  = 0;
        while (true) {
            $chunk = $this->shipments->page($since, $offset, $batch);
            if ($chunk === []) {
                break;
            }
            foreach ($chunk as $ship) {
                fputcsv($fh, [
                    $ship->id,
                    $ship->orderId,
                    $ship->dispatchedAt->format(DATE_ATOM),
                    $ship->carrier,
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

        $key = sprintf('exports/shipments/%s.csv.gz', $since->format('Ymd-His'));
        $this->uploader->upload($this->bucket, $key, $gz);
        unlink($gz);

        return new ExportSummary('shipments', $count, $key);
    }
}
