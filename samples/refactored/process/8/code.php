<?php
declare(strict_types=1);

namespace App\Console\Mail;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use App\Database\Connection;
use App\Mail\Mailer;
use App\Templating\TemplateRenderer;
use App\Mail\CampaignProfile;
use App\Mail\CampaignRegistry;

final class SendCampaignCommand extends Command
{
    protected static $defaultName = 'mail:campaign';

    public function __construct(
        private Connection $db,
        private Mailer $mailer,
        private TemplateRenderer $tpl,
        private CampaignRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('campaign', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $c = $this->registry->get((string) $in->getArgument('campaign'));
        $t0 = microtime(true);
        $recipients = $this->db->fetchAll($c->recipientsSql);
        $out->writeln('Sending to ' . count($recipients) . ' recipients');
        $ok = 0;
        $fail = 0;
        foreach ($recipients as $r) {
            try {
                $html = $this->tpl->render($c->template, ['name' => $r['name']]);
                $this->mailer->sendHtml($r['email'], $c->subject, $html);
                $this->db->insert('mail_log', ['campaign' => $c->name, 'user_id' => $r['id'], 'status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')]);
                $ok++;
            } catch (\Throwable $e) {
                $this->db->insert('mail_log', ['campaign' => $c->name, 'user_id' => $r['id'], 'status' => 'failed', 'error' => $e->getMessage(), 'sent_at' => date('Y-m-d H:i:s')]);
                $fail++;
            }
        }
        $elapsed = microtime(true) - $t0;
        $out->writeln("<info>Sent {$ok}, failed {$fail} in " . number_format($elapsed, 2) . 's</info>');
        return Command::SUCCESS;
    }
}
