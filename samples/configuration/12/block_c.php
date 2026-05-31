<?php

declare(strict_types=1);

namespace App\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

#[AsCommand(
    name: 'app:sync-products',
    description: 'Synchronize product data with external marketplace'
)]
final class SyncProductsCommand extends Command
{
    private const SYNC_RATE_LIMIT = 100;
    private const SYNC_WINDOW_SECONDS = 60;
    private const SYNC_BURST_ALLOWANCE = 20;
    private const SYNC_BACKOFF_DELAY = 30;
    private const SYNC_BATCH_SIZE = 50;

    private array $rateLimitState = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_OPTIONAL,
            'Maximum number of products to sync',
            self::SYNC_BATCH_SIZE
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) $input->getOption('limit');
        $this->initializeRateLimitState();

        $output->writeln('<info>Starting product synchronization...</info>');

        $products = $this->fetchProductList($batchSize);

        foreach ($products as $product) {
            $this->waitForRateLimit();

            try {
                $result = $this->syncSingleProduct($product);

                if ($result['success']) {
                    $output->writeln(sprintf(
                        '<info>✓ Synced product %s</info>',
                        $product['sku']
                    ));
                } else {
                    $output->writeln(sprintf(
                        '<error>✗ Failed to sync %s: %s</error>',
                        $product['sku'],
                        $result['error'] ?? 'Unknown error'
                    ));
                }
            } catch (\Exception $e) {
                $this->handleSyncError($product, $e, $output);
            }
        }

        $output->writeln(sprintf(
            '<info>Synchronization complete. Processed %d products.</info>',
            count($products)
        ));

        return Command::SUCCESS;
    }

    private function initializeRateLimitState(): void
    {
        $this->rateLimitState = [
            'requests_made' => 0,
            'window_start' => time(),
            'burst_count' => 0,
            'last_backoff' => 0,
        ];
    }

    private function waitForRateLimit(): void
    {
        $now = time();
        $windowDuration = self::SYNC_WINDOW_SECONDS;
        $maxRequests = self::SYNC_RATE_LIMIT;
        $burstAllowance = self::SYNC_BURST_ALLOWANCE;
        $backoffDelay = self::SYNC_BACKOFF_DELAY;

        if ($now - $this->rateLimitState['window_start'] >= $windowDuration) {
            $this->rateLimitState['requests_made'] = 0;
            $this->rateLimitState['window_start'] = $now;
            $this->rateLimitState['burst_count'] = 0;
        }

        $effectiveMax = $maxRequests + $burstAllowance;

        if ($this->rateLimitState['requests_made'] >= $effectiveMax) {
            $sleepTime = $windowDuration - ($now - $this->rateLimitState['window_start']);

            $this->logger->warning('Rate limit would be exceeded, waiting', [
                'sleep_time' => $sleepTime,
                'requests_made' => $this->rateLimitState['requests_made'],
                'max' => $effectiveMax,
            ]);

            sleep(max(1, $sleepTime));
            $this->rateLimitState['window_start'] = time();
            $this->rateLimitState['requests_made'] = 0;
        }

        if ($this->rateLimitState['burst_count'] >= $burstAllowance) {
            $backoffDuration = $backoffDelay * ($this->rateLimitState['burst_count'] - $burstAllowance + 1);

            $this->logger->info('Burst limit reached, applying backoff', [
                'backoff_duration' => min($backoffDuration, $backoffDelay * 3),
                'burst_count' => $this->rateLimitState['burst_count'],
            ]);

            sleep(min($backoffDuration, $backoffDelay * 3));
        }

        $this->rateLimitState['requests_made']++;
        $this->rateLimitState['burst_count']++;
    }

    private function fetchProductList(int $limit): array
    {
        $this->logger->info('Fetching product list', ['limit' => $limit]);
        return [];
    }

    private function syncSingleProduct(array $product): array
    {
        return ['success' => true];
    }

    private function handleSyncError(array $product, \Exception $e, OutputInterface $output): void
    {
        $this->logger->error('Product sync failed', [
            'sku' => $product['sku'] ?? 'unknown',
            'error' => $e->getMessage(),
            'rate_limit_state' => $this->rateLimitState,
        ]);

        $output->writeln(sprintf(
            '<error>Error syncing %s: %s</error>',
            $product['sku'] ?? 'unknown',
            $e->getMessage()
        ));
    }
}
