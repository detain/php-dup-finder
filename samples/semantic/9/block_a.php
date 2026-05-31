<?php

declare(strict_types=1);

namespace Acme\Docs\Export;

use Acme\Docs\Model\Document;
use Acme\Docs\Writer\PdfWriter;
use Acme\Docs\Exception\NotPublishedException;

final class PdfExportService
{
    public function __construct(private PdfWriter $writer)
    {
    }

    public function export(Document $doc, string $path): string
    {
        $state = $doc->state();
        $publishedAt = $doc->publishedAt();
        $frozen = $doc->currentRevision()->isFrozen();

        $isFinal = $state === 'published'
            && $publishedAt !== null
            && $frozen;

        if (!$isFinal) {
            throw new NotPublishedException(
                'Document ' . $doc->id() . ' is not finalized; cannot export.'
            );
        }

        $payload = [
            'title'  => $doc->title(),
            'author' => $doc->author(),
            'body'   => $doc->renderBody(),
        ];

        $this->writer->write($path, $payload);
        return $path;
    }
}
