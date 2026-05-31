<?php
declare(strict_types=1);

namespace App\Console\Onboard;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Mail\Mailer;
use App\Onboard\TenantProfile;
use App\Onboard\TenantProfileRegistry;

final class OnboardTenantCommand extends Command
{
    protected static $defaultName = 'onboard:tenant';

    public function __construct(
        private Connection $db,
        private Mailer $mailer,
        private TenantProfileRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::REQUIRED);
        $this->addArgument('name', InputArgument::REQUIRED);
        $this->addArgument('email', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $p = $this->registry->get((string) $in->getArgument('type'));
        $name = (string) $in->getArgument('name');
        $email = (string) $in->getArgument('email');
        $this->db->beginTransaction();
        try {
            $tid = $this->db->insert('tenants', ['name' => $name, 'type' => $p->type, 'created_at' => date('Y-m-d H:i:s')]);
            $this->db->insert('users', ['tenant_id' => $tid, 'email' => $email, 'role' => $p->ownerRole]);
            foreach ($p->modules as $m) {
                $this->db->insert('tenant_modules', ['tenant_id' => $tid, 'module' => $m]);
            }
            $this->db->insert('billing_plans', ['tenant_id' => $tid, 'plan' => $p->plan, 'price_cents' => $p->priceCents]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            $out->writeln('<error>Onboard failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $this->mailer->sendTemplate($email, $p->welcomeTemplate, ['name' => $name, 'login_url' => $p->loginUrl]);
        $this->db->insert('audit_log', ['event' => 'tenant_onboarded', 'tenant_id' => $tid, 'kind' => $p->type, 'run_at' => date('Y-m-d H:i:s')]);
        $out->writeln("<info>{$p->type} tenant {$tid} onboarded</info>");
        return Command::SUCCESS;
    }
}
