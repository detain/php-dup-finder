<?php
declare(strict_types=1);

namespace App\Console\Commands\Import;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use App\Database\Connection;
use App\Mail\Mailer;

final class ImportProductsCommand extends Command
{
    protected static $defaultName = 'import:products';

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
        $csv = $in->getArgument('file');
        $stream = fopen($csv, 'r');
        if ($stream === false) {
            $out->writeln('<error>Open failed</error>');
            return Command::FAILURE;
        }
        $head = fgetcsv($stream);
        $bad = [];
        $added = 0;
        $changed = 0;
        $idx = 1;
        while (($data = fgetcsv($stream)) !== false) {
            $idx++;
            if (count($data) !== count($head)) {
                $bad[] = "Row {$idx}: invalid columns";
                continue;
            }
            $rec = array_combine($head, $data);
            if (empty($rec['sku']) || empty($rec['name'])) {
                $bad[] = "Row {$idx}: missing required";
                continue;
            }
            $hit = $this->db->fetchOne(
                'SELECT id FROM products WHERE sku = ?',
                [$rec['sku']]
            );
            if ($hit) {
                $this->db->update('products', $rec, ['id' => $hit['id']]);
                $changed++;
            } else {
                $this->db->insert('products', $rec);
                $added++;
            }
        }
        fclose($stream);
        $msg = "Product import finished.\nAdded: {$added}\nChanged: {$changed}\nBad rows: " . count($bad);
        $this->mailer->send('catalog@example.com', 'Product Import Summary', $msg);
        $out->writeln($msg);
        return Command::SUCCESS;
    }
}
