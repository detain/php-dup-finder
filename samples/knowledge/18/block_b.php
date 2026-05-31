<?php
declare(strict_types=1);

namespace Database\Schema;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

final class CreateUsersTable
{
    public const TABLE_NAME = 'users';
    public const EMAIL_MAX_LENGTH = 254;
    public const EMAIL_MIN_LENGTH = 5;

    public function up(Schema $schema): void
    {
        $table = $this->schema->createTable(self::TABLE_NAME);

        $table->addColumn('id', Types::GUID, ['notnull' => true]);

        $table->addColumn('email', Types::STRING, [
            'length' => self::EMAIL_MAX_LENGTH,
            'notnull' => true,
            'comment' => 'Email address, max 254 chars per RFC 5321'
        ]);

        $table->addColumn('email_normalized', Types::STRING, [
            'length' => self::EMAIL_MAX_LENGTH,
            'notnull' => true,
            'comment' => 'Lowercase, trimmed email for case-insensitive comparison'
        ]);

        $table->addColumn('email_verified', Types::BOOLEAN, [
            'notnull' => true,
            'default' => false
        ]);

        $table->addColumn('email_verified_at', Types::DATETIME_IMMUTABLE, [
            'notnull' => false
        ]);

        $table->addColumn('password_hash', Types::STRING, [
            'length' => 255,
            'notnull' => true
        ]);

        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['email_normalized'], 'uniq_users_email_normalized');

        $this->addCheckConstraint($table,
            'LENGTH(email) >= ' . self::EMAIL_MIN_LENGTH,
            'chk_email_min_length'
        );
    }
}
