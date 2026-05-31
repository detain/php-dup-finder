<?php

declare(strict_types=1);

namespace App\Export;

class UserCsvExporter
{
    private string $delimiter = ',';
    private string $enclosure = '"';
    private string $lineEnding = "\r\n";

    public function export(array $users, string $filepath): int
    {
        $handle = fopen($filepath, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open file for writing: {$filepath}");
        }

        $this->writeHeader($handle);
        $rowCount = 0;

        foreach ($users as $user) {
            $this->writeRow($handle, $user);
            $rowCount++;
        }

        fclose($handle);

        return $rowCount;
    }

    public function exportToString(array $users): string
    {
        $handle = fopen('php://memory', 'r+');

        $this->writeHeader($handle);

        foreach ($users as $user) {
            $this->writeRow($handle, $user);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    private function writeHeader($handle): void
    {
        $headers = [
            'ID',
            'Email',
            'Name',
            'Avatar URL',
            'Is Active',
            'Roles',
            'Created At',
            'Updated At'
        ];

        fputcsv($handle, $headers, $this->delimiter, $this->enclosure, $this->lineEnding);
    }

    private function writeRow($handle, User $user): void
    {
        $row = [
            $user->getId(),
            $user->getEmail(),
            $user->getName(),
            $user->getAvatarUrl() ?? '',
            $user->isActive() ? 'Yes' : 'No',
            implode(';', $user->getRoles()),
            $user->getCreatedAt()->format('Y-m-d H:i:s'),
            $user->getUpdatedAt()?->format('Y-m-d H:i:s') ?? ''
        ];

        fputcsv($handle, $row, $this->delimiter, $this->enclosure, $this->lineEnding);
    }

    public function generateFilename(string $prefix = 'export'): string
    {
        $timestamp = date('Y-m-d_His');
        return "{$prefix}_{$timestamp}.csv";
    }

    public function setDelimiter(string $delimiter): void
    {
        $this->delimiter = $delimiter;
    }

    public function setEnclosure(string $enclosure): void
    {
        $this->enclosure = $enclosure;
    }

    public function getContentType(): string
    {
        return 'text/csv; charset=utf-8';
    }
}
