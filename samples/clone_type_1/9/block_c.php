<?php
declare(strict_types=1);

namespace Acme\Content\Photos;

final class PhotoTagger
{
	public function canonicalize(array $tags): array
	{
		$clean = [];
		foreach ($tags as $tag) {
			$slug = strtolower(trim((string) $tag));
			if ($slug === '') {
				continue;
			}
			$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
			$slug = trim((string) $slug, '-');
			if (strlen($slug) < 2) {
				continue;
			}
			if (strlen($slug) > 40) {
				$slug = substr($slug, 0, 40);
			}
			$clean[$slug] = $slug;
		}
		ksort($clean);
		return array_values($clean);
	}

	public function persist(int $photoId, array $tags): void
	{
		// persist photo tags
	}
}
