<?php
declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

final class CreateUsersTableMigration extends AbstractMigration
{
    public const TABLE_NAME = 'users';
    public const PASSWORD_MIN_LENGTH = 8;
    public const PASSWORD_MAX_LENGTH = 128;

    public function up(Schema $schema): void
    {
        $table = $this->createTable(self::TABLE_NAME);

        $table->addColumn('id', Types::GUID, ['notnull' => true]);
        $table->addColumn('email', Types::STRING, [
            'length' => 255,
            'notnull' => true,
            'unique' => true
        ]);

        $table->addColumn('password_hash', Types::STRING, [
            'length' => 255,
            'notnull' => true,
            'comment' => 'Argon2id hash of password'
        ]);

        $table->addColumn('password_changed_at', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
            'comment' => 'When the password was last changed'
        ]);

        $table->addColumn('password_expires_at', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
            'comment' => 'When the password expires (max 365 days from change)'
        ]);

        $table->addColumn('failed_login_attempts', Types::INTEGER, [
            'notnull' => true,
            'default' => 0
        ]);

        $table->addColumn('locked_until', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
            'comment' => 'Account lockout expiration time'
        ]);

        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['email'], 'idx_users_email');

        $this->addSql('CREATE RULE password_length_check AS
            SELECT LENGTH(password_hash) >= ' . self::PASSWORD_MIN_LENGTH . '
            AND LENGTH(password_hash) <= ' . self::PASSWORD_MAX_LENGTH . '
            WHERE password_hash IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->dropTable(self::TABLE_NAME);
    }

    public function getDescription(): string
    {
        return 'Creates users table with password policy fields. ' .
            'Password constraints: min ' . self::PASSWORD_MIN_LENGTH . ', max ' . self::PASSWORD_MAX_LENGTH . ' characters.';
    }
}
