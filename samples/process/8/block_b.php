<?php
declare(strict_types=1);

namespace App\Console\Mail;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Mail\Mailer;
use App\Templating\TemplateRenderer;

final class SendAbandonedCartCommand extends Command
{
    protected static $defaultName = 'mail:abandoned-cart';

    public function __construct(
        private Connection $db,
        private Mailer $mailer,
        private TemplateRenderer $tpl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $t0 = microtime(true);
        $list = $this->db->fetchAll(
            'SELECT u.id, u.email, u.name
             FROM users u JOIN carts c ON c.user_id = u.id
             WHERE c.updated_at < NOW() - INTERVAL 2 DAY AND c.checked_out_at IS NULL'
        );
        $out->writeln('Sending to ' . count($list) . ' carts');
        $ok = 0;
        $bad = 0;
        foreach ($list as $person) {
            try {
                $body = $this->tpl->render('mail/abandoned_cart.html.twig', [
                    'name' => $person['name'],
                ]);
                $this->mailer->sendHtml($person['email'], 'You left something behind', $body);
                $this->db->insert('mail_log', [
                    'campaign' => 'abandoned_cart',
                    'user_id' => $person['id'],
                    'status' => 'sent',
                    'sent_at' => date('Y-m-d H:i:s'),
                ]);
                $ok++;
            } catch (\Throwable $e) {
                $this->db->insert('mail_log', [
                    'campaign' => 'abandoned_cart',
                    'user_id' => $person['id'],
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'sent_at' => date('Y-m-d H:i:s'),
                ]);
                $bad++;
            }
        }
        $dt = microtime(true) - $t0;
        $out->writeln("<info>Sent {$ok}, failed {$bad} in " . number_format($dt, 2) . 's</info>');
        return Command::SUCCESS;
    }
}
