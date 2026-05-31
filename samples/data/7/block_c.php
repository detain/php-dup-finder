<?php
declare(strict_types=1);

namespace App\Commands\Imports;

use App\Bus\CommandHandlerInterface;
use App\Database\Connection;
use App\Validators\ImportRecordValidator;
use Psr\Log\LoggerInterface;

final class ProcessCsvImportHandler implements CommandHandlerInterface
{
    public function __construct(
        private Connection $db,
        private ImportRecordValidator $validator,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(object $command): array
    {
        $importId = (int)$command->importId;
        $startTime = microtime(true);

        $import = $this->db->fetchOne(
            'SELECT id, file_path, target_table, owner_id FROM imports WHERE id = ?',
            [$importId]
        );

        if ($import === null) {
            throw new \RuntimeException('Import job missing: ' . $importId);
        }

        $path = (string)$import['file_path'];
        if (!is_readable($path)) {
            throw new \RuntimeException('Import file not readable: ' . $path);
        }

        set_time_limit(30);

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open import file');
        }

        $header = fgetcsv($handle);
        $processed = 0;
        $errors = [];

        while (($row = fgetcsv($handle)) !== false) {
            $assoc = array_combine($header, $row);
            $errorList = $this->validator->validate($assoc);

            if ($errorList !== []) {
                $errors[] = ['row' => $processed + 1, 'errors' => $errorList];
            } else {
                $this->db->execute(
                    'INSERT INTO ' . $import['target_table'] . ' (data_json, imported_at) VALUES (?, NOW())',
                    [json_encode($assoc)]
                );
            }
            $processed++;

            $elapsed = microtime(true) - $startTime;
            if ($elapsed > 30) {
                $this->logger->warning('Import timeout hit', ['rows_so_far' => $processed]);
                break;
            }
        }

        fclose($handle);
        return ['processed' => $processed, 'errors' => $errors];
    }
}
