<?php
namespace Fixtures\Sql;

class UserRepo
{
    public function findById(\PDO $db, int $id): ?array
    {
        $sql = "SELECT * FROM users WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}

class ProductRepo
{
    public function findById(\PDO $db, int $id): ?array
    {
        $sql = "SELECT * FROM products WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}

class OrderRepo
{
    public function findById(\PDO $db, int $id): ?array
    {
        $sql = "SELECT * FROM orders WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
