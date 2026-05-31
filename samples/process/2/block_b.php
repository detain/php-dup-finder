<?php
declare(strict_types=1);

namespace App\Console\Deploy;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Database\Snapshot;
use App\Migrations\InventoryMigrator;
use App\Smoke\InventorySmokeTest;

final class DeployInventoryMigrationCommand extends Command
{
    protected static $defaultName = 'deploy:inventory';

    public function __construct(
        private Connection $db,
        private Snapshot $snap,
        private InventoryMigrator $mig,
        private InventorySmokeTest $smoke,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $out->writeln('Running pre-checks…');
        if (!$this->db->ping()) {
            $out->writeln('<error>Database not reachable</error>');
            return Command::FAILURE;
        }
        if ($this->db->fetchValue('SELECT COUNT(*) FROM inventory WHERE qty < 0') > 0) {
            $out->writeln('<error>Negative qty rows, aborting</error>');
            return Command::FAILURE;
        }
        $out->writeln('Taking snapshot…');
        $sid = $this->snap->take('inventory');
        if ($sid === null) {
            $out->writeln('<error>Snapshot failure</error>');
            return Command::FAILURE;
        }
        $out->writeln("Created snapshot {$sid}");
        try {
            $out->writeln('Applying migration…');
            $this->mig->run();
        } catch (\Throwable $e) {
            $out->writeln('<error>Migration crashed: '.$e->getMessage().'</error>');
            $this->snap->restore($sid);
            return Command::FAILURE;
        }
        $out->writeln('Running smoke tests…');
        if (!$this->smoke->run()) {
            $out->writeln('<error>Smoke tests failed, rolling back</error>');
            $this->snap->restore($sid);
            return Command::FAILURE;
        }
        $out->writeln('<info>Inventory deploy succeeded</info>');
        return Command::SUCCESS;
    }
}
