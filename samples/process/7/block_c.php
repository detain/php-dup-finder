<?php
declare(strict_types=1);

namespace App\Console\Onboard;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Mail\Mailer;

final class OnboardAgencyPartnerCommand extends Command
{
    protected static $defaultName = 'onboard:agency';

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
        $agency = (string) $in->getArgument('name');
        $primary = (string) $in->getArgument('email');
        $this->db->beginTransaction();
        try {
            $tenantRow = $this->db->insert('tenants', [
                'name' => $agency, 'type' => 'agency', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $owner = $this->db->insert('users', [
                'tenant_id' => $tenantRow, 'email' => $primary, 'role' => 'partner',
            ]);
            foreach (['white_label', 'multi_client', 'commissions'] as $feature) {
                $this->db->insert('tenant_modules', ['tenant_id' => $tenantRow, 'module' => $feature]);
            }
            $this->db->insert('billing_plans', [
                'tenant_id' => $tenantRow, 'plan' => 'agency-pro', 'price_cents' => 19900,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            $out->writeln('<error>Onboard failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $this->mailer->sendTemplate(
            $primary,
            'welcome.agency',
            ['name' => $agency, 'login_url' => 'https://partners.example.com/login']
        );
        $this->db->insert('audit_log', [
            'event' => 'tenant_onboarded',
            'tenant_id' => $tenantRow,
            'kind' => 'agency',
            'run_at' => date('Y-m-d H:i:s'),
        ]);
        $out->writeln("<info>Agency tenant {$tenantRow} onboarded</info>");
        return Command::SUCCESS;
    }
}
