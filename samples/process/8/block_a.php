<?php
declare(strict_types=1);

namespace App\Console\Mail;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Mail\Mailer;
use App\Templating\TemplateRenderer;

final class SendWeeklyNewsletterCommand extends Command
{
    protected static $defaultName = 'mail:weekly-newsletter';

    public function __construct(
        private Connection $db,
        private Mailer $mailer,
        private TemplateRenderer $tpl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $start = microtime(true);
        $recipients = $this->db->fetchAll(
            'SELECT id, email, name FROM users WHERE newsletter_opt_in = 1 AND unsubscribed_at IS NULL'
        );
        $out->writeln('Sending to ' . count($recipients) . ' recipients');
        $sent = 0;
        $failed = 0;
        foreach ($recipients as $r) {
            try {
                $html = $this->tpl->render('mail/weekly_newsletter.html.twig', [
                    'name' => $r['name'],
                ]);
                $this->mailer->sendHtml($r['email'], 'Your weekly digest', $html);
                $this->db->insert('mail_log', [
                    'campaign' => 'weekly_newsletter',
                    'user_id' => $r['id'],
                    'status' => 'sent',
                    'sent_at' => date('Y-m-d H:i:s'),
                ]);
                $sent++;
            } catch (\Throwable $e) {
                $this->db->insert('mail_log', [
                    'campaign' => 'weekly_newsletter',
                    'user_id' => $r['id'],
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'sent_at' => date('Y-m-d H:i:s'),
                ]);
                $failed++;
            }
        }
        $elapsed = microtime(true) - $start;
        $out->writeln("<info>Sent {$sent}, failed {$failed} in " . number_format($elapsed, 2) . 's</info>');
        return Command::SUCCESS;
    }
}
