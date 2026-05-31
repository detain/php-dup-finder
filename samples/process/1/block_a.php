<?php
declare(strict_types=1);

namespace App\Console\Commands\Import;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use App\Database\Connection;
use App\Mail\Mailer;

final class ImportVendorsCommand extends Command
{
    protected static $defaultName = 'import:vendors';

    public function __construct(private Connection $db, private Mailer $mailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $file = $in->getArgument('file');
        $handle = fopen($file, 'r');
        if ($handle === false) {
            $out->writeln('<error>Cannot open file</error>');
            return Command::FAILURE;
        }
        $header = fgetcsv($handle);
        $errors = [];
        $inserted = 0;
        $updated = 0;
        $row = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            if (count($data) !== count($header)) {
                $errors[] = "Row {$row}: column mismatch";
                continue;
            }
            $record = array_combine($header, $data);
            if (empty($record['name']) || empty($record['tax_id'])) {
                $errors[] = "Row {$row}: missing required field";
                continue;
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM vendors WHERE tax_id = ?',
                [$record['tax_id']]
            );
            if ($existing) {
                $this->db->update('vendors', $record, ['id' => $existing['id']]);
                $updated++;
            } else {
                $this->db->insert('vendors', $record);
                $inserted++;
            }
        }
        fclose($handle);
        $body = "Vendor import complete.\nInserted: {$inserted}\nUpdated: {$updated}\nErrors: " . count($errors);
        $this->mailer->send('procurement@example.com', 'Vendor Import Summary', $body);
        $out->writeln($body);
        return Command::SUCCESS;
    }
}
