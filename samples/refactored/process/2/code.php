<?php
declare(strict_types=1);

namespace App\Console\Deploy;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Database\Snapshot;
use App\Deploy\MigrationProfile;
use App\Deploy\MigrationProfileRegistry;

final class DeployMigrationCommand extends Command
{
    protected static $defaultName = 'deploy:run';

    public function __construct(
        private Connection $db,
        private Snapshot $snap,
        private MigrationProfileRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('profile', \Symfony\Component\Console\Input\InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $profile = $this->registry->get((string) $in->getArgument('profile'));
        return $this->deploy($profile, $out);
    }

    private function deploy(MigrationProfile $p, OutputInterface $out): int
    {
        $out->writeln("Pre-checks for {$p->name}…");
        if (!$this->db->ping()) {
            $out->writeln('<error>DB unreachable</error>');
            return Command::FAILURE;
        }
        foreach ($p->preChecks as $label => $sql) {
            if ($this->db->fetchValue($sql) > 0) {
                $out->writeln("<error>Pre-check failed: {$label}</error>");
                return Command::FAILURE;
            }
        }
        $sid = $this->snap->take($p->name);
        if ($sid === null) {
            $out->writeln('<error>Snapshot failed</error>');
            return Command::FAILURE;
        }
        try {
            ($p->migrator)();
        } catch (\Throwable $e) {
            $out->writeln('<error>Migration error: '.$e->getMessage().'</error>');
            $this->snap->restore($sid);
            return Command::FAILURE;
        }
        if (!($p->smoke)()) {
            $out->writeln('<error>Smoke failed, rolling back</error>');
            $this->snap->restore($sid);
            return Command::FAILURE;
        }
        $out->writeln("<info>{$p->name} deploy OK</info>");
        return Command::SUCCESS;
    }
}
