<?php
declare(strict_types=1);

namespace App\Console\Commands\Import;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use App\Database\Connection;
use App\Mail\Mailer;

final class ImportCustomersCommand extends Command
{
    protected static $defaultName = 'import:customers';

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
        $path = $in->getArgument('file');
        $fp = fopen($path, 'r');
        if ($fp === false) {
            $out->writeln('<error>Could not open CSV</error>');
            return Command::FAILURE;
        }
        $cols = fgetcsv($fp);
        $problems = [];
        $created = 0;
        $modified = 0;
        $line = 1;
        while (($cells = fgetcsv($fp)) !== false) {
            $line++;
            if (count($cells) !== count($cols)) {
                $problems[] = "Line {$line}: bad column count";
                continue;
            }
            $row = array_combine($cols, $cells);
            if (empty($row['email']) || empty($row['name'])) {
                $problems[] = "Line {$line}: required fields missing";
                continue;
            }
            $found = $this->db->fetchOne(
                'SELECT id FROM customers WHERE email = ?',
                [$row['email']]
            );
            if ($found) {
                $this->db->update('customers', $row, ['id' => $found['id']]);
                $modified++;
            } else {
                $this->db->insert('customers', $row);
                $created++;
            }
        }
        fclose($fp);
        $summary = "Customer import done.\nCreated: {$created}\nModified: {$modified}\nProblems: " . count($problems);
        $this->mailer->send('crm@example.com', 'Customer Import Summary', $summary);
        $out->writeln($summary);
        return Command::SUCCESS;
    }
}
