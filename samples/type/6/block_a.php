<?php
declare(strict_types=1);

namespace Acme\Cms\Hydration;

use Acme\Cms\Article;
use Acme\Cms\Exceptions\HydrationException;

final class ArticleHydrator
{
    public function hydrate(array $row): Article
    {
        if (!isset($row['id'], $row['title'], $row['body'])) {
            throw new HydrationException('article: missing required columns');
        }

        $id        = (int)$row['id'];
        $title     = (string)$row['title'];
        $body      = (string)$row['body'];
        $slug      = isset($row['slug']) ? (string)$row['slug'] : '';
        $status    = isset($row['status']) ? (string)$row['status'] : 'draft';
        $views     = isset($row['view_count']) ? (int)$row['view_count'] : 0;

        $published = null;
        if (!empty($row['published_at'])) {
            try {
                $published = new \DateTimeImmutable((string)$row['published_at']);
            } catch (\Throwable $e) {
                throw new HydrationException('article: bad published_at', 0, $e);
            }
        }
        $created = null;
        if (!empty($row['created_at'])) {
            try {
                $created = new \DateTimeImmutable((string)$row['created_at']);
            } catch (\Throwable $e) {
                throw new HydrationException('article: bad created_at', 0, $e);
            }
        }
        $updated = null;
        if (!empty($row['updated_at'])) {
            try {
                $updated = new \DateTimeImmutable((string)$row['updated_at']);
            } catch (\Throwable $e) {
                throw new HydrationException('article: bad updated_at', 0, $e);
            }
        }

        $tags = [];
        if (isset($row['tags']) && $row['tags'] !== '') {
            $decoded = json_decode((string)$row['tags'], true);
            if (is_array($decoded)) {
                $tags = array_values(array_map('strval', $decoded));
            }
        }

        return new Article($id, $title, $body, $slug, $status, $views, $published, $created, $updated, $tags);
    }
}
