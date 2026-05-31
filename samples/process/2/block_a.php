<?php
declare(strict_types=1);

namespace App\Console\Deploy;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Database\Snapshot;
use App\Migrations\OrdersMigrator;
use App\Smoke\OrdersSmokeTest;

final class DeployOrdersMigrationCommand extends Command
{
    protected static $defaultName = 'deploy:orders';

    public function __construct(
        private Connection $db,
        private Snapshot $snap,
        private OrdersMigrator $mig,
        private OrdersSmokeTest $smoke,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $out->writeln('Pre-checks…');
        if (!$this->db->ping()) {
            $out->writeln('<error>DB unreachable</error>');
            return Command::FAILURE;
        }
        if ($this->db->fetchValue('SELECT COUNT(*) FROM orders WHERE status IS NULL') > 0) {
            $out->writeln('<error>Null statuses present, aborting</error>');
            return Command::FAILURE;
        }
        $out->writeln('Snapshot…');
        $snapId = $this->snap->take('orders');
        if ($snapId === null) {
            $out->writeln('<error>Snapshot failed</error>');
            return Command::FAILURE;
        }
        $out->writeln("Snapshot {$snapId} created");
        try {
            $out->writeln('Migrating…');
            $this->mig->run();
        } catch (\Throwable $e) {
            $out->writeln('<error>Migration error: '.$e->getMessage().'</error>');
            $this->snap->restore($snapId);
            return Command::FAILURE;
        }
        $out->writeln('Smoke testing…');
        if (!$this->smoke->run()) {
            $out->writeln('<error>Smoke failed, rolling back</error>');
            $this->snap->restore($snapId);
            return Command::FAILURE;
        }
        $out->writeln('<info>Orders deploy OK</info>');
        return Command::SUCCESS;
    }
}
