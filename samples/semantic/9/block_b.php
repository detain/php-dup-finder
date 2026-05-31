<?php

declare(strict_types=1);

namespace Acme\Search\Indexing;

use Acme\Search\Model\Document;
use Acme\Search\Enum\DocStatus;
use Acme\Search\Adapter\SearchClient;

final class DocumentIndexer
{
    public function __construct(private SearchClient $client)
    {
    }

    /** @param iterable<Document> $documents */
    public function indexAll(iterable $documents): int
    {
        $indexed = 0;

        foreach ($documents as $doc) {
            $status = $doc->status();
            $hasPublishStamp = $doc->publishedAt() instanceof \DateTimeImmutable;
            $revisionFrozen = $doc->revision()->frozen();

            $publishable = $status === DocStatus::PUBLISHED
                && $hasPublishStamp
                && $revisionFrozen;

            if (!$publishable) {
                continue;
            }

            $this->client->index('docs', [
                'id'    => $doc->id(),
                'title' => $doc->title(),
                'body'  => $doc->plainText(),
            ]);
            $indexed++;
        }

        return $indexed;
    }
}
