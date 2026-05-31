<?php
declare(strict_types=1);

namespace App\Console\Deploy;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Database\Snapshot;
use App\Migrations\BillingMigrator;
use App\Smoke\BillingSmokeTest;

final class DeployBillingMigrationCommand extends Command
{
    protected static $defaultName = 'deploy:billing';

    public function __construct(
        private Connection $db,
        private Snapshot $snap,
        private BillingMigrator $mig,
        private BillingSmokeTest $smoke,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $out->writeln('Doing pre-flight checks…');
        if (!$this->db->ping()) {
            $out->writeln('<error>Cannot reach DB</error>');
            return Command::FAILURE;
        }
        if ($this->db->fetchValue('SELECT COUNT(*) FROM invoices WHERE total < 0') > 0) {
            $out->writeln('<error>Negative invoices detected</error>');
            return Command::FAILURE;
        }
        $out->writeln('Creating backup snapshot…');
        $snapshot = $this->snap->take('billing');
        if ($snapshot === null) {
            $out->writeln('<error>Backup failed</error>');
            return Command::FAILURE;
        }
        $out->writeln("Backup {$snapshot} ready");
        try {
            $out->writeln('Migrating billing schema…');
            $this->mig->run();
        } catch (\Throwable $e) {
            $out->writeln('<error>Migration failure: '.$e->getMessage().'</error>');
            $this->snap->restore($snapshot);
            return Command::FAILURE;
        }
        $out->writeln('Running smoke tests on billing…');
        if (!$this->smoke->run()) {
            $out->writeln('<error>Smoke failed, restoring backup</error>');
            $this->snap->restore($snapshot);
            return Command::FAILURE;
        }
        $out->writeln('<info>Billing deploy complete</info>');
        return Command::SUCCESS;
    }
}
