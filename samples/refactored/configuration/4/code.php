<?php
declare(strict_types=1);

namespace Acme\Database;

use PDO;

final class DatabaseProvider
{
    public const DSN        = 'mysql:host=db-primary.internal;port=3306;dbname=acme;charset=utf8mb4';
    public const USER       = 'acme_app';
    public const PASS       = 'acme_app_secret';
    public const ISOLATION  = 'REPEATABLE-READ';
    public const PERSISTENT = true;

    private static ?PDO $shared = null;

    public static function get(): PDO
    {
        if (self::$shared instanceof PDO) {
            return self::$shared;
        }

        $pdo = new PDO(self::DSN, self::USER, self::PASS, [
            PDO::ATTR_PERSISTENT         => self::PERSISTENT,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION transaction_isolation = '" . self::ISOLATION . "'",
        ]);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

        return self::$shared = $pdo;
    }
}

// Usage: $db = DatabaseProvider::get();
