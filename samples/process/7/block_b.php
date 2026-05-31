<?php
declare(strict_types=1);

namespace App\Console\Onboard;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Mail\Mailer;

final class OnboardInternalTeamCommand extends Command
{
    protected static $defaultName = 'onboard:internal';

    public function __construct(
        private Connection $db,
        private Mailer $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED);
        $this->addArgument('email', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $teamName = (string) $in->getArgument('name');
        $contact = (string) $in->getArgument('email');
        $this->db->beginTransaction();
        try {
            $tid = $this->db->insert('tenants', [
                'name' => $teamName, 'type' => 'internal', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $uid = $this->db->insert('users', [
                'tenant_id' => $tid, 'email' => $contact, 'role' => 'admin',
            ]);
            foreach (['dashboards', 'reports', 'metrics', 'admin'] as $mod) {
                $this->db->insert('tenant_modules', ['tenant_id' => $tid, 'module' => $mod]);
            }
            $this->db->insert('billing_plans', [
                'tenant_id' => $tid, 'plan' => 'internal-free', 'price_cents' => 0,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            $out->writeln('<error>Onboard failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $this->mailer->sendTemplate(
            $contact,
            'welcome.internal',
            ['name' => $teamName, 'login_url' => 'https://internal.example.com/sso']
        );
        $this->db->insert('audit_log', [
            'event' => 'tenant_onboarded',
            'tenant_id' => $tid,
            'kind' => 'internal',
            'run_at' => date('Y-m-d H:i:s'),
        ]);
        $out->writeln("<info>Internal tenant {$tid} onboarded</info>");
        return Command::SUCCESS;
    }
}
