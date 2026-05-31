<?php
declare(strict_types=1);

namespace DataProcessing\Pipeline;

use Psr\Log\LoggerInterface;

final class CustomerDataPipeline
{
    private const BUFFER_SIZE = 1000;
    private const FLUSH_INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CustomerValidator $validator,
        private readonly CustomerTransformer $transformer,
        private readonly CustomerRepository $repository,
    ) {}

    public function process(array $rawRecords): PipelineResult
    {
        $this->logger->info('Starting customer data pipeline', [
            'record_count' => count($rawRecords),
        ]);

        $normalizedRecords = $this->normalize($rawRecords);
        $validatedRecords = $this->validate($normalizedRecords);
        $transformedRecords = $this->transform($validatedRecords);
        $enrichedRecords = $this->enrich($transformedRecords);
        $persistedRecords = $this->persist($enrichedRecords);

        $this->logger->info('Customer data pipeline completed', [
            'processed' => count($persistedRecords),
            'failed' => count($rawRecords) - count($persistedRecords),
        ]);

        return new PipelineResult(
            inputCount: count($rawRecords),
            outputCount: count($persistedRecords),
            failedCount: count($rawRecords) - count($persistedRecords),
            metrics: $this->gatherMetrics($persistedRecords),
        );
    }

    private function normalize(array $rawRecords): array
    {
        $normalized = [];

        foreach ($rawRecords as $record) {
            $cleanRecord = [
                'id' => $this->normalizeIdentifier($record['id'] ?? null),
                'email' => strtolower(trim($record['email'] ?? '')),
                'first_name' => $this->normalizeName($record['first_name'] ?? ''),
                'last_name' => $this->normalizeName($record['last_name'] ?? ''),
                'phone' => $this->normalizePhone($record['phone'] ?? ''),
                'address' => $this->normalizeAddress($record['address'] ?? []),
                'created_at' => $this->normalizeTimestamp($record['created_at'] ?? null),
            ];

            $normalized[] = $cleanRecord;
        }

        return $normalized;
    }

    private function validate(array $records): array
    {
        $validRecords = [];
        $errors = [];

        foreach ($records as $index => $record) {
            $violations = $this->validator->validate($record);

            if (count($violations) > 0) {
                $errors[$index] = $violations;
                continue;
            }

            $validRecords[$index] = $record;
        }

        $this->logger->debug('Validation completed', [
            'valid' => count($validRecords),
            'invalid' => count($errors),
        ]);

        return $validRecords;
    }

    private function transform(array $records): array
    {
        $transformed = [];

        foreach ($records as $record) {
            $transformed[] = $this->transformer->transform($record);
        }

        return $transformed;
    }

    private function enrich(array $records): array
    {
        $enriched = [];

        foreach ($records as $record) {
            $enrichedRecord = $record;

            $enrichedRecord['email_domain'] = $this->extractEmailDomain($record['email']);
            $enrichedRecord['full_name'] = $record['first_name'] . ' ' . $record['last_name'];
            $enrichedRecord['is_vip'] = $this->determineVipStatus($record);
            $enrichedRecord['segment'] = $this->calculateSegment($record);

            $enriched[] = $enrichedRecord;
        }

        return $enriched;
    }

    private function persist(array $records): array
    {
        $persisted = [];
        $buffer = [];
        $lastFlush = time();

        foreach ($records as $record) {
            $buffer[] = $record;

            if (count($buffer) >= self::BUFFER_SIZE || (time() - $lastFlush) >= self::FLUSH_INTERVAL_SECONDS) {
                $this->flushBuffer($buffer);
                $persisted = array_merge($persisted, $buffer);
                $buffer = [];
                $lastFlush = time();
            }
        }

        if (count($buffer) > 0) {
            $this->flushBuffer($buffer);
            $persisted = array_merge($persisted, $buffer);
        }

        return $persisted;
    }

    private function flushBuffer(array $buffer): void
    {
        foreach ($buffer as $record) {
            try {
                $this->repository->upsert($record);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to persist record', [
                    'error' => $e->getMessage(),
                    'record' => $record,
                ]);
            }
        }
    }

    private function normalizeIdentifier(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return bin2hex(random_bytes(8));
        }

        return preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    }

    private function normalizeName(string $name): string
    {
        return ucwords(strtolower(trim($name)));
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 10) {
            return sprintf('+1-%s-%s', substr($digits, 0, 3), substr($digits, 3, 3));
        }

        return $phone;
    }

    private function normalizeAddress(array $address): array
    {
        return [
            'street' => $this->normalizeName($address['street'] ?? ''),
            'city' => $this->normalizeName($address['city'] ?? ''),
            'state' => strtoupper($address['state'] ?? ''),
            'postal_code' => preg_replace('/\D/', '', $address['postal_code'] ?? ''),
            'country' => strtoupper($address['country'] ?? 'US'),
        ];
    }

    private function normalizeTimestamp(?string $timestamp): \DateTimeImmutable
    {
        if ($timestamp === null) {
            return new \DateTimeImmutable();
        }

        try {
            return new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            return new \DateTimeImmutable();
        }
    }

    private function extractEmailDomain(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? 'unknown';
    }

    private function determineVipStatus(array $record): bool
    {
        $totalSpend = $record['total_spend'] ?? 0;
        $orderCount = $record['order_count'] ?? 0;

        return $totalSpend > 10000 || $orderCount > 50;
    }

    private function calculateSegment(array $record): string
    {
        $totalSpend = $record['total_spend'] ?? 0;

        if ($totalSpend > 50000) {
            return 'enterprise';
        }

        if ($totalSpend > 10000) {
            return 'premium';
        }

        if ($totalSpend > 1000) {
            return 'regular';
        }

        return 'new';
    }

    private function gatherMetrics(array $records): array
    {
        return [
            'vip_count' => count(array_filter($records, fn($r) => $r['is_vip'] ?? false)),
            'segment_distribution' => array_count_values(array_column($records, 'segment')),
        ];
    }
}
