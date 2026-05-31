<?php
declare(strict_types=1);

namespace Acme\Search;

use Acme\Search\Exceptions\IndexException;
use Psr\Log\LoggerInterface;

interface DocumentBuilder
{
    public function entityName(): string;
    public function indexName(): string;
    public function find(int $id): ?object;
    /** @return array<string,mixed> */
    public function build(object $entity): array;
}

final class SearchIndexer
{
    public function __construct(
        private readonly SearchClient $client,
        private readonly Metrics $metrics,
        private readonly LoggerInterface $log
    ) {
    }

    /** @param array<int|string> $ids */
    public function reindexBatch(DocumentBuilder $builder, array $ids): int
    {
        $name  = $builder->entityName();
        $bulk  = $this->client->openBulk($builder->indexName());
        $count = 0;
        $start = microtime(true);
        try {
            foreach ($ids as $id) {
                $entity = $builder->find((int)$id);
                if ($entity === null) {
                    $bulk->delete((string)$id);
                    continue;
                }
                $bulk->upsert((string)$id, $builder->build($entity));
                $count++;
            }
            $bulk->commit();
            $this->metrics->increment("search.{$name}.indexed", $count);
        } catch (\Throwable $e) {
            $bulk->discard();
            $this->log->error("{$name} reindex failed", ['err' => $e->getMessage()]);
            $this->metrics->increment("search.{$name}.failed");
            throw new IndexException("{$name} index failed", 0, $e);
        } finally {
            $this->metrics->timing("search.{$name}.duration", microtime(true) - $start);
        }
        return $count;
    }
}
