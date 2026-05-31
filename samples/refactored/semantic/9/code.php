<?php

declare(strict_types=1);

namespace Acme\Shared\Policy;

use Acme\Shared\Model\Document;

final class DocumentPublishedPolicy
{
    public function isPublished(Document $doc): bool
    {
        $statusName = is_object($doc->status())
            ? (string) $doc->status()->value
            : (string) $doc->status();

        if (strtolower($statusName) !== 'published') {
            return false;
        }

        if (!$doc->publishedAt() instanceof \DateTimeImmutable) {
            return false;
        }

        return $doc->currentRevision()->isFrozen();
    }
}

final class PdfExportService
{
    public function __construct(private DocumentPublishedPolicy $policy) {}

    public function export(Document $doc, string $path): string
    {
        if (!$this->policy->isPublished($doc)) {
            throw new \DomainException('Document is not finalized.');
        }
        return $path;
    }
}

final class ShareLinkGenerator
{
    public function __construct(private DocumentPublishedPolicy $policy) {}

    public function ensureShareable(Document $doc): void
    {
        if (!$this->policy->isPublished($doc)) {
            throw new \DomainException('Only published documents can be shared.');
        }
    }
}
