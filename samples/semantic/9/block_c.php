<?php

declare(strict_types=1);

namespace Acme\Sharing\Links;

use Acme\Sharing\Model\Document;
use Acme\Sharing\Repository\LinkRepository;
use Acme\Sharing\Exception\NotShareableException;

final class ShareLinkGenerator
{
    public function __construct(private LinkRepository $links)
    {
    }

    public function createShareLink(Document $doc, string $createdBy): string
    {
        if (!$doc->isPublished()) {
            throw new NotShareableException(
                'Only published documents can be shared.'
            );
        }

        $token = bin2hex(random_bytes(16));
        $this->links->save([
            'document_id' => $doc->id(),
            'token' => $token,
            'created_by' => $createdBy,
            'created_at' => (new \DateTimeImmutable())->format('c'),
        ]);

        return sprintf('https://share.example.com/d/%s', $token);
    }
}
