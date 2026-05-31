<?php
declare(strict_types=1);

namespace Acme\Cms\Hydration;

use Acme\Cms\Author;
use Acme\Cms\Exceptions\HydrationException;

final class AuthorHydrator
{
    public function hydrate(array $row): Author
    {
        if (!isset($row['id'], $row['email'], $row['display_name'])) {
            throw new HydrationException('author: missing required columns');
        }

        $id          = (int)$row['id'];
        $email       = (string)$row['email'];
        $displayName = (string)$row['display_name'];
        $bio         = isset($row['bio']) ? (string)$row['bio'] : '';
        $status      = isset($row['status']) ? (string)$row['status'] : 'active';
        $karma       = isset($row['karma']) ? (int)$row['karma'] : 0;

        $verified = null;
        if (!empty($row['verified_at'])) {
            try {
                $verified = new \DateTimeImmutable((string)$row['verified_at']);
            } catch (\Throwable $e) {
                throw new HydrationException('author: bad verified_at', 0, $e);
            }
        }
        $created = null;
        if (!empty($row['created_at'])) {
            try {
                $created = new \DateTimeImmutable((string)$row['created_at']);
            } catch (\Throwable $e) {
                throw new HydrationException('author: bad created_at', 0, $e);
            }
        }
        $updated = null;
        if (!empty($row['updated_at'])) {
            try {
                $updated = new \DateTimeImmutable((string)$row['updated_at']);
            } catch (\Throwable $e) {
                throw new HydrationException('author: bad updated_at', 0, $e);
            }
        }

        $roles = [];
        if (isset($row['roles']) && $row['roles'] !== '') {
            $decoded = json_decode((string)$row['roles'], true);
            if (is_array($decoded)) {
                $roles = array_values(array_map('strval', $decoded));
            }
        }

        return new Author($id, $email, $displayName, $bio, $status, $karma, $verified, $created, $updated, $roles);
    }
}
