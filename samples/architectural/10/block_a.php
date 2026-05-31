<?php
declare(strict_types=1);

namespace App\Etl\Contacts;

final class ContactRow
{
    public function __construct(public string $email, public string $name, public string $phone) {}
}

final class ContactParser
{
    /** @return \Generator<int, array> */
    public function parse(string $path): \Generator
    {
        $fh = fopen($path, 'r') ?: throw new \RuntimeException("cannot open {$path}");
        $headers = fgetcsv($fh);
        if ($headers === false) {
            return;
        }
        while (($row = fgetcsv($fh)) !== false) {
            yield array_combine($headers, $row);
        }
        fclose($fh);
    }
}

final class ContactTransformer
{
    public function transform(array $raw): ContactRow
    {
        return new ContactRow(
            strtolower(trim((string) ($raw['email'] ?? ''))),
            trim((string) ($raw['name'] ?? '')),
            preg_replace('/\D+/', '', (string) ($raw['phone'] ?? '')) ?? '',
        );
    }
}

final class ContactLoader
{
    /** @var list<ContactRow> */
    private array $buffer = [];

    public function __construct(private \PDO $pdo, private int $batchSize = 100) {}

    public function load(ContactRow $row): void
    {
        $this->buffer[] = $row;
        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare('INSERT INTO contacts (email, name, phone) VALUES (?, ?, ?)');
        foreach ($this->buffer as $row) {
            $stmt->execute([$row->email, $row->name, $row->phone]);
        }
        $this->pdo->commit();
        $this->buffer = [];
    }
}
