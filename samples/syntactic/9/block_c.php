<?php
declare(strict_types=1);

namespace Acme\Sitemap;

final class SitemapUrlFeeder
{
    public function __construct(
        private SitemapSource $source,
        private UrlAnnotator $annotator,
    ) {
    }

    /**
     * @return \Generator<int, array{0:string, 1:array<string,string>}>
     */
    public function feed(string $hostname): \Generator
    {
        foreach ($this->source->entriesFor($hostname) as $entryIndex => $entry) {
            if (isset($entry['alternates'])) {
                yield from $this->expandAlternates($entryIndex, $entry);
            } else {
                yield [
                    sprintf('url-%d', $entryIndex),
                    $this->annotator->annotate($entry),
                ];
            }
        }
    }

    /**
     * @return \Generator<int, array{0:string, 1:array<string,string>}>
     */
    private function expandAlternates(int $entryIndex, array $entry): \Generator
    {
        foreach ($entry['alternates'] as $altIndex => $alt) {
            yield [
                sprintf('url-%d.%d', $entryIndex, $altIndex),
                $this->annotator->annotate($alt),
            ];
        }
    }
}
