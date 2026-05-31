<?php
declare(strict_types=1);

namespace Acme\Cms\Hydration;

use Acme\Cms\Comment;
use Acme\Cms\Exceptions\HydrationException;

final class CommentHydrator
{
    public function hydrate(array $row): Comment
    {
        if (!isset($row['id'], $row['article_id'], $row['body'])) {
            throw new HydrationException('comment: missing required columns');
        }

        $id        = (int)$row['id'];
        $articleId = (int)$row['article_id'];
        $body      = (string)$row['body'];
        $authorId  = isset($row['author_id']) ? (int)$row['author_id'] : 0;
        $status    = isset($row['status']) ? (string)$row['status'] : 'pending';
        $score     = isset($row['score']) ? (int)$row['score'] : 0;

        $approved = null;
        if (!empty($row['approved_at'])) {
            try {
                $approved = new \DateTimeImmutable((string)$row['approved_at']);
            } catch (\Throwable $e) {
                throw new HydrationException('comment: bad approved_at', 0, $e);
            }
        }
        $created = null;
        if (!empty($row['created_at'])) {
            try {
                $created = new \DateTimeImmutable((string)$row['created_at']);
            } catch (\Throwable $e) {
                throw new HydrationException('comment: bad created_at', 0, $e);
            }
        }
        $updated = null;
        if (!empty($row['updated_at'])) {
            try {
                $updated = new \DateTimeImmutable((string)$row['updated_at']);
            } catch (\Throwable $e) {
                throw new HydrationException('comment: bad updated_at', 0, $e);
            }
        }

        $mentions = [];
        if (isset($row['mentions']) && $row['mentions'] !== '') {
            $decoded = json_decode((string)$row['mentions'], true);
            if (is_array($decoded)) {
                $mentions = array_values(array_map('strval', $decoded));
            }
        }

        return new Comment($id, $articleId, $body, $authorId, $status, $score, $approved, $created, $updated, $mentions);
    }
}
