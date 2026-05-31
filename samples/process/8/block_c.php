<?php
declare(strict_types=1);

namespace App\Console\Mail;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Mail\Mailer;
use App\Templating\TemplateRenderer;

final class SendWinBackCommand extends Command
{
    protected static $defaultName = 'mail:win-back';

    public function __construct(
        private Connection $db,
        private Mailer $mailer,
        private TemplateRenderer $tpl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $began = microtime(true);
        $dormant = $this->db->fetchAll(
            'SELECT id, email, name FROM users
             WHERE last_login_at < NOW() - INTERVAL 90 DAY AND unsubscribed_at IS NULL'
        );
        $out->writeln('Sending to ' . count($dormant) . ' dormant users');
        $delivered = 0;
        $bounced = 0;
        foreach ($dormant as $u) {
            try {
                $rendered = $this->tpl->render('mail/win_back.html.twig', [
                    'name' => $u['name'],
                ]);
                $this->mailer->sendHtml($u['email'], 'We miss you', $rendered);
                $this->db->insert('mail_log', [
                    'campaign' => 'win_back',
                    'user_id' => $u['id'],
                    'status' => 'sent',
                    'sent_at' => date('Y-m-d H:i:s'),
                ]);
                $delivered++;
            } catch (\Throwable $e) {
                $this->db->insert('mail_log', [
                    'campaign' => 'win_back',
                    'user_id' => $u['id'],
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'sent_at' => date('Y-m-d H:i:s'),
                ]);
                $bounced++;
            }
        }
        $elapsed = microtime(true) - $began;
        $out->writeln("<info>Sent {$delivered}, failed {$bounced} in " . number_format($elapsed, 2) . 's</info>');
        return Command::SUCCESS;
    }
}
