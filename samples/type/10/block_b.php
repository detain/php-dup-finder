<?php
declare(strict_types=1);

namespace Acme\Search\Indexers;

use Acme\Search\SearchClient;
use Acme\Search\Exceptions\IndexException;
use Acme\Search\Metrics;
use Acme\Cms\ArticleRepository;
use Psr\Log\LoggerInterface;

final class ArticleSearchIndexer
{
    public function __construct(
        private readonly SearchClient $client,
        private readonly ArticleRepository $repo,
        private readonly Metrics $metrics,
        private readonly LoggerInterface $log,
        private readonly string $indexName = 'articles_v3'
    ) {
    }

    public function reindexBatch(array $articleIds): int
    {
        $bulk = $this->client->openBulk($this->indexName);
        $count = 0;
        $start = microtime(true);
        try {
            foreach ($articleIds as $id) {
                $article = $this->repo->find((int)$id);
                if ($article === null) {
                    $bulk->delete((string)$id);
                    continue;
                }
                $doc = [
                    'id'           => $article->id,
                    'type'         => 'article',
                    'updated_at'   => $article->updatedAt->format(DATE_ATOM),
                    'boost'        => $article->isPinned ? 3.0 : 1.0,
                    'title'        => $article->title,
                    'slug'         => $article->slug,
                    'body'         => $article->body,
                    'author_id'    => $article->authorId,
                    'published_at' => $article->publishedAt?->format(DATE_ATOM),
                    'tags'         => $article->tags,
                    'word_count'   => str_word_count($article->body),
                ];
                $bulk->upsert((string)$article->id, $doc);
                $count++;
            }
            $bulk->commit();
            $this->metrics->increment('search.article.indexed', $count);
        } catch (\Throwable $e) {
            $bulk->discard();
            $this->log->error('article reindex failed', ['err' => $e->getMessage()]);
            $this->metrics->increment('search.article.failed');
            throw new IndexException('article index failed', 0, $e);
        } finally {
            $this->metrics->timing('search.article.duration', microtime(true) - $start);
        }
        return $count;
    }
}
