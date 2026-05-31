<?php
declare(strict_types=1);

namespace App\Console\Commands\Import;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use App\Database\Connection;
use App\Mail\Mailer;

final class CsvUpsertImportCommand extends Command
{
    /** @var array<string, array{table:string, key:string, required:list<string>, recipient:string, label:string}> */
    private const PROFILES = [
        'vendors'   => ['table' => 'vendors',   'key' => 'tax_id', 'required' => ['name', 'tax_id'], 'recipient' => 'procurement@example.com', 'label' => 'Vendor'],
        'customers' => ['table' => 'customers', 'key' => 'email',  'required' => ['email', 'name'],  'recipient' => 'crm@example.com',         'label' => 'Customer'],
        'products'  => ['table' => 'products',  'key' => 'sku',    'required' => ['sku', 'name'],    'recipient' => 'catalog@example.com',     'label' => 'Product'],
    ];

    protected static $defaultName = 'import:csv';

    public function __construct(private Connection $db, private Mailer $mailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('profile', InputArgument::REQUIRED)
             ->addArgument('file', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $profile = self::PROFILES[$in->getArgument('profile')] ?? null;
        if ($profile === null) {
            $out->writeln('<error>Unknown profile</error>');
            return Command::FAILURE;
        }
        $fp = fopen($in->getArgument('file'), 'r');
        if ($fp === false) {
            return Command::FAILURE;
        }
        $head = fgetcsv($fp);
        $errors = $inserted = $updated = 0;
        $line = 1;
        while (($cells = fgetcsv($fp)) !== false) {
            $line++;
            if (count($cells) !== count($head)) { $errors++; continue; }
            $row = array_combine($head, $cells);
            foreach ($profile['required'] as $req) {
                if (empty($row[$req])) { $errors++; continue 2; }
            }
            $hit = $this->db->fetchOne("SELECT id FROM {$profile['table']} WHERE {$profile['key']} = ?", [$row[$profile['key']]]);
            if ($hit) { $this->db->update($profile['table'], $row, ['id' => $hit['id']]); $updated++; }
            else { $this->db->insert($profile['table'], $row); $inserted++; }
        }
        fclose($fp);
        $body = "{$profile['label']} import done.\nInserted: {$inserted}\nUpdated: {$updated}\nErrors: {$errors}";
        $this->mailer->send($profile['recipient'], "{$profile['label']} Import Summary", $body);
        $out->writeln($body);
        return Command::SUCCESS;
    }
}
