<?php
declare(strict_types=1);

namespace App\Etl;

/** @template T of object */
interface Parser
{
    /** @return \Generator<int, array> */
    public function parse(string $path): \Generator;
}

/** @template T of object */
interface Transformer
{
    /** @return T */
    public function transform(array $raw): object;
}

/** @template T of object */
final class BatchLoader
{
    /** @var list<object> */
    private array $buffer = [];

    /**
     * @param list<string> $columns  Property names on T (also the SQL column names)
     */
    public function __construct(
        private \PDO $pdo,
        private string $table,
        private array $columns,
        private int $batchSize = 100,
    ) {}

    public function load(object $row): void
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
        $cols = implode(',', $this->columns);
        $placeholders = implode(',', array_fill(0, count($this->columns), '?'));
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} ({$cols}) VALUES ({$placeholders})");
        foreach ($this->buffer as $row) {
            $stmt->execute(array_map(fn(string $c) => $row->$c, $this->columns));
        }
        $this->pdo->commit();
        $this->buffer = [];
    }
}

/** @template T of object */
final class EtlPipeline
{
    /** @param Parser<T> $parser @param Transformer<T> $transformer @param BatchLoader<T> $loader */
    public function __construct(
        private Parser $parser,
        private Transformer $transformer,
        private BatchLoader $loader,
    ) {}

    public function run(string $path): void
    {
        foreach ($this->parser->parse($path) as $raw) {
            $this->loader->load($this->transformer->transform($raw));
        }
        $this->loader->flush();
    }
}
