<?php
declare(strict_types=1);

namespace DataProcessing\Shared;

interface DataPipelineStrategy
{
    public function normalize(array $rawRecords): array;
    public function validate(array $records): array;
    public function transform(array $records): array;
    public function enrich(array $records): array;
    public function persist(array $records): array;
}

abstract class BaseDataPipeline
{
    protected LoggerInterface $logger;
    protected ValidatorInterface $validator;
    protected TransformerInterface $transformer;
    protected RepositoryInterface $repository;

    private const BUFFER_SIZE = 1000;
    private const FLUSH_INTERVAL_SECONDS = 300;

    public function process(array $rawRecords): PipelineResult
    {
        $this->logger->info('Starting data pipeline', [
            'record_count' => count($rawRecords),
        ]);

        $normalized = $this->normalize($rawRecords);
        $validated = $this->validate($normalized);
        $transformed = $this->transform($validated);
        $enriched = $this->enrich($transformed);
        $persisted = $this->persist($enriched);

        return new PipelineResult(
            inputCount: count($rawRecords),
            outputCount: count($persisted),
            failedCount: count($rawRecords) - count($persisted),
            metrics: $this->gatherMetrics($persisted),
        );
    }

    protected function persistWithBuffer(array $records, callable $upsert): array
    {
        $persisted = [];
        $buffer = [];
        $lastFlush = time();

        foreach ($records as $record) {
            $buffer[] = $record;

            if (count($buffer) >= self::BUFFER_SIZE || (time() - $lastFlush) >= self::FLUSH_INTERVAL_SECONDS) {
                foreach ($buffer as $item) {
                    try {
                        $upsert($item);
                        $persisted[] = $item;
                    } catch (\Throwable $e) {
                        $this->logger->error('Persist failed', ['error' => $e->getMessage()]);
                    }
                }
                $buffer = [];
                $lastFlush = time();
            }
        }

        if (count($buffer) > 0) {
            foreach ($buffer as $item) {
                try {
                    $upsert($item);
                    $persisted[] = $item;
                } catch (\Throwable $e) {
                    $this->logger->error('Persist failed', ['error' => $e->getMessage()]);
                }
            }
        }

        return $persisted;
    }

    protected function normalizeIdentifier(?string $id): string
    {
        if ($id === null || $id === '') {
            return bin2hex(random_bytes(8));
        }
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    }

    protected function normalizeTimestamp(?string $timestamp): \DateTimeImmutable
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

    abstract protected function normalize(array $rawRecords): array;
    abstract protected function validate(array $records): array;
    abstract protected function transform(array $records): array;
    abstract protected function enrich(array $records): array;
    abstract protected function persist(array $records): array;
    abstract protected function gatherMetrics(array $records): array;
}

final class CustomerDataPipeline extends BaseDataPipeline
{
    protected function normalize(array $rawRecords): array
    {
        return array_map(fn($r) => [
            'id' => $this->normalizeIdentifier($r['id'] ?? null),
            'email' => strtolower(trim($r['email'] ?? '')),
            'first_name' => ucwords(strtolower(trim($r['first_name'] ?? ''))),
            'last_name' => ucwords(strtolower(trim($r['last_name'] ?? ''))),
        ], $rawRecords);
    }

    protected function validate(array $records): array
    {
        return array_filter($records, fn($r) => filter_var($r['email'], FILTER_VALIDATE_EMAIL) !== false);
    }

    protected function transform(array $records): array
    {
        return array_map(fn($r) => $this->transformer->transform($r), $records);
    }

    protected function enrich(array $records): array
    {
        return array_map(function ($r) {
            $r['full_name'] = $r['first_name'] . ' ' . $r['last_name'];
            return $r;
        }, $records);
    }

    protected function persist(array $records): array
    {
        return $this->persistWithBuffer($records, fn($r) => $this->repository->upsert($r));
    }

    protected function gatherMetrics(array $records): array
    {
        return ['record_count' => count($records)];
    }
}
