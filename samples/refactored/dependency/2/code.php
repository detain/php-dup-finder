<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Database\DatabaseConnection;

/**
 * Base repository class providing database connection.
 * Centralizes DatabaseConnection injection to avoid duplication.
 */
abstract class BaseRepository
{
    protected DatabaseConnection $db;
    protected string $table;

    public function __construct(DatabaseConnection $db, string $table)
    {
        $this->db = $db;
        $this->table = $table;
    }

    protected function query(string $sql, array $params = [])
    {
        return $this->db->query($sql, $params);
    }

    protected function getLastInsertId(): string
    {
        return $this->db->getLastInsertId();
    }
}

/**
 * User repository extending base.
 */
class UserRepository extends BaseRepository
{
    public function __construct(DatabaseConnection $db)
    {
        parent::__construct($db, 'users');
    }
}
