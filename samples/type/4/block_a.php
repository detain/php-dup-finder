<?php
declare(strict_types=1);

namespace Acme\Export\Order;

use Acme\Orders\OrderQueryService;
use Acme\Export\S3Uploader;
use Acme\Export\ExportSummary;
use Acme\Export\Exceptions\ExportFailure;

final class OrderExporter
{
    public function __construct(
        private readonly OrderQueryService $orders,
        private readonly S3Uploader $uploader,
        private readonly string $bucket
    ) {
    }

    public function export(\DateTimeImmutable $since): ExportSummary
    {
        $tmp = tempnam(sys_get_temp_dir(), 'orders-');
        if ($tmp === false) {
            throw new ExportFailure('temp file');
        }
        $fh = fopen($tmp, 'w');
        fputcsv($fh, ['order_id', 'customer_id', 'placed_at', 'total']);

        $offset = 0;
        $batch  = 500;
        $count  = 0;
        while (true) {
            $chunk = $this->orders->page($since, $offset, $batch);
            if ($chunk === []) {
                break;
            }
            foreach ($chunk as $order) {
                fputcsv($fh, [
                    $order->id,
                    $order->customerId,
                    $order->placedAt->format(DATE_ATOM),
                    number_format($order->total, 2, '.', ''),
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

        $key = sprintf('exports/orders/%s.csv.gz', $since->format('Ymd-His'));
        $this->uploader->upload($this->bucket, $key, $gz);
        unlink($gz);

        return new ExportSummary('orders', $count, $key);
    }
}
