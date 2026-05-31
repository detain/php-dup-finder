<?php
declare(strict_types=1);

namespace App\Console\Backfill;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Slug\SlugGenerator;
use Psr\Log\LoggerInterface;

final class BackfillPostSlugCommand extends Command
{
    protected static $defaultName = 'backfill:post-slug';

    public function __construct(
        private Connection $db,
        private SlugGenerator $slugger,
        private LoggerInterface $log,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $size = 500;
        $touched = 0;
        $bad = 0;
        $startTime = microtime(true);
        while (true) {
            $items = $this->db->fetchAll(
                'SELECT id, title FROM posts WHERE slug IS NULL AND title IS NOT NULL LIMIT ?',
                [$size]
            );
            if ($items === []) {
                break;
            }
            foreach ($items as $item) {
                try {
                    $slug = $this->slugger->fromTitle($item['title']);
                    $this->db->update('posts', ['slug' => $slug], ['id' => $item['id']]);
                    $touched++;
                } catch (\Throwable $e) {
                    $bad++;
                    $this->log->warning('post slug gen failed', ['id' => $item['id']]);
                }
            }
            $out->writeln("Slugged {$touched}…");
        }
        $stillNull = $this->db->fetchValue(
            'SELECT COUNT(*) FROM posts WHERE slug IS NULL AND title IS NOT NULL'
        );
        $duration = microtime(true) - $startTime;
        $this->log->info('post slug backfill complete', [
            'updated' => $touched, 'errors' => $bad, 'remaining' => $stillNull, 'elapsed' => $duration,
        ]);
        $out->writeln("<info>Done. Updated {$touched}, errors {$bad}, remaining {$stillNull}</info>");
        return Command::SUCCESS;
    }
}
