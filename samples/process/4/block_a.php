<?php
declare(strict_types=1);

namespace App\Console\Backfill;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Geo\IpResolver;
use Psr\Log\LoggerInterface;

final class BackfillUserCountryCommand extends Command
{
    protected static $defaultName = 'backfill:user-country';

    public function __construct(
        private Connection $db,
        private IpResolver $ip,
        private LoggerInterface $log,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $batch = 500;
        $total = 0;
        $errors = 0;
        $started = microtime(true);
        while (true) {
            $rows = $this->db->fetchAll(
                'SELECT id, last_ip FROM users WHERE country IS NULL AND last_ip IS NOT NULL LIMIT ?',
                [$batch]
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                try {
                    $country = $this->ip->resolve($row['last_ip']);
                    $this->db->update('users', ['country' => $country], ['id' => $row['id']]);
                    $total++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->log->warning('user country resolve failed', ['id' => $row['id']]);
                }
            }
            $out->writeln("Processed {$total}…");
        }
        $remaining = $this->db->fetchValue(
            'SELECT COUNT(*) FROM users WHERE country IS NULL AND last_ip IS NOT NULL'
        );
        $elapsed = microtime(true) - $started;
        $this->log->info('user country backfill complete', [
            'updated' => $total, 'errors' => $errors, 'remaining' => $remaining, 'elapsed' => $elapsed,
        ]);
        $out->writeln("<info>Done. Updated {$total}, errors {$errors}, remaining {$remaining}</info>");
        return Command::SUCCESS;
    }
}
