<?php
declare(strict_types=1);

namespace App\Console\Onboard;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Mail\Mailer;

final class OnboardB2BCustomerCommand extends Command
{
    protected static $defaultName = 'onboard:b2b';

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
        $name = (string) $in->getArgument('name');
        $email = (string) $in->getArgument('email');
        $this->db->beginTransaction();
        try {
            $tenantId = $this->db->insert('tenants', [
                'name' => $name, 'type' => 'b2b', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $userId = $this->db->insert('users', [
                'tenant_id' => $tenantId, 'email' => $email, 'role' => 'owner',
            ]);
            foreach (['orders', 'invoices', 'shipments'] as $module) {
                $this->db->insert('tenant_modules', ['tenant_id' => $tenantId, 'module' => $module]);
            }
            $this->db->insert('billing_plans', [
                'tenant_id' => $tenantId, 'plan' => 'b2b-starter', 'price_cents' => 4900,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            $out->writeln('<error>Onboard failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $this->mailer->sendTemplate(
            $email,
            'welcome.b2b',
            ['name' => $name, 'login_url' => 'https://app.example.com/login']
        );
        $this->db->insert('audit_log', [
            'event' => 'tenant_onboarded',
            'tenant_id' => $tenantId,
            'kind' => 'b2b',
            'run_at' => date('Y-m-d H:i:s'),
        ]);
        $out->writeln("<info>B2B tenant {$tenantId} onboarded</info>");
        return Command::SUCCESS;
    }
}
