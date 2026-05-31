<?php

namespace App\Services\DataProcessing;

final class BatchConfig
{
    public readonly string $typePrefix;
    public readonly string $separator;
    public readonly int $runDigits;

    public function __construct(
        string $typePrefix = 'BTH',
        string $separator = '-',
        int $runDigits = 4
    ) {
        $this->typePrefix = $typePrefix;
        $this->separator = $separator;
        $this->runDigits = $runDigits;
    }
}

final class BatchIdService
{
    private BatchConfig $config;

    public function __construct(BatchConfig $config)
    {
        $this->config = $config;
    }

    public function generate(string $taskType, int $runNumber, \DateTimeImmutable $timestamp): string
    {
        $datePart = $timestamp->format('Ymd');
        $timePart = $timestamp->format('His');
        $typePart = strtoupper(substr($taskType, 0, 3));
        $runPart = str_pad((string) ($runNumber % 10000), $this->config->runDigits, '0', STR_PAD_LEFT);

        return $typePart . $datePart . $timePart . $this->config->separator . $runPart;
    }

    public function generateExtended(string $taskType, int $runNumber, string $context, \DateTimeImmutable $timestamp): string
    {
        $datePart = $timestamp->format('Ymd');
        $timePart = $timestamp->format('His');
        $typePart = strtoupper(substr($taskType, 0, 3));
        $contextPart = strtoupper(substr($context, 0, 2));
        $runPart = str_pad((string) ($runNumber % 10000), $this->config->runDigits, '0', STR_PAD_LEFT);

        return $contextPart . $typePart . $datePart . $timePart . $this->config->separator . $runPart;
    }

    public function parse(string $batchId): array
    {
        if (!preg_match('/^([A-Z]{3})(\d{8})(\d{6})' . preg_quote($this->config->separator, '/') . '(\d{' . $this->config->runDigits . '})$/', $batchId, $matches)) {
            throw new \InvalidArgumentException("Invalid batch ID: {$batchId}");
        }

        return [
            'type' => $matches[1],
            'date' => $matches[2],
            'time' => $matches[3],
            'run_number' => (int) $matches[4],
        ];
    }

    public function getTimestamp(string $batchId): \DateTimeImmutable
    {
        $parsed = $this->parse($batchId);

        return new \DateTimeImmutable(
            substr($parsed['date'], 0, 4) . '-' . substr($parsed['date'], 4, 2) . '-' . substr($parsed['date'], 6, 2)
            . ' ' . substr($parsed['time'], 0, 2) . ':' . substr($parsed['time'], 2, 2) . ':' . substr($parsed['time'], 4, 2)
        );
    }
}
