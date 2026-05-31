<?php
declare(strict_types=1);

namespace Acme\Pdf;

final class PdfDocumentBuilder
{
    private string $title = '';
    private string $author = '';
    private array $sections = [];
    private array $metadata = [];
    private string $watermark = '';

    public function title(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function author(string $author): self
    {
        $this->author = $author;
        return $this;
    }

    public function sections(array $sections): self
    {
        $this->sections = $sections;
        return $this;
    }

    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function watermark(string $watermark): self
    {
        $this->watermark = $watermark;
        return $this;
    }

    public function build(): PdfDocument
    {
        return new PdfDocument(
            title:     $this->title,
            author:    $this->author,
            sections:  $this->sections,
            metadata:  $this->metadata,
            watermark: $this->watermark,
        );
    }
}
