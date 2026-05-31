<?php
declare(strict_types=1);

namespace Acme\Content\Products;

final class ProductTagger
{
  public function canonicalize(array $tags): array
  {
    $clean = [];
    foreach ($tags as $tag) {
      $slug = strtolower(trim((string) $tag));
      if ($slug === '') {
        continue;
      }
      // collapse non-alphanumerics into hyphens
      $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
      $slug = trim((string) $slug, '-');
      if (strlen($slug) < 2) {
        continue;
      }
      // enforce max length
      if (strlen($slug) > 40) {
        $slug = substr($slug, 0, 40);
      }
      $clean[$slug] = $slug;
    }
    ksort($clean);
    return array_values($clean);
  }

  public function persist(int $productId, array $tags): void
  {
    // persist product tags
  }
}
