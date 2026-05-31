<?php
declare(strict_types=1);

namespace App\Commands\Exports;

use App\Bus\CommandHandlerInterface;
use App\Storage\S3Client;
use App\Database\Connection;
use Psr\Log\LoggerInterface;

final class ExportCustomerDataHandler implements CommandHandlerInterface
{
    public function __construct(
        private S3Client $s3,
        private Connection $db,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(object $command): array
    {
        $exportId = (int)$command->exportId;
        $started = microtime(true);

        $job = $this->db->fetchOne(
            'SELECT id, customer_id, requested_at, status, format FROM data_exports WHERE id = ?',
            [$exportId]
        );

        if ($job === null) {
            throw new \RuntimeException('Export not found');
        }

        if ($job['status'] !== 'queued') {
            $this->logger->info('Skipping non-queued export', ['id' => $exportId, 'status' => $job['status']]);
            return ['skipped' => true];
        }

        set_time_limit(30);

        $rows = $this->db->fetchAll(
            'SELECT * FROM customer_data_view WHERE customer_id = ?',
            [(int)$job['customer_id']]
        );

        if (count($rows) === 0) {
            $this->db->execute(
                'UPDATE data_exports SET status = ?, finished_at = NOW() WHERE id = ?',
                ['empty', $exportId]
            );
            return ['rows' => 0];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'export_');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create temp file');
        }

        $fh = fopen($tmp, 'wb');
        foreach ($rows as $row) {
            fputcsv($fh, $row);
            if ((microtime(true) - $started) > 30) {
                fclose($fh);
                unlink($tmp);
                throw new \RuntimeException('Export exceeded timeout of 30 seconds');
            }
        }
        fclose($fh);

        $key = sprintf('exports/%d/%s.csv', (int)$job['customer_id'], date('Ymd-His'));
        $this->s3->putObject($key, $tmp);
        unlink($tmp);

        return ['ok' => true, 's3_key' => $key, 'rows' => count($rows)];
    }
}
