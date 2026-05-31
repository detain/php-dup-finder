<?php
declare(strict_types=1);

namespace Database\Schema;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

final class CreateProductsTable
{
    public const TABLE_NAME = 'products';
    public const CODE_MIN_LENGTH = 3;
    public const CODE_MAX_LENGTH = 30;
    public const CODE_REGEX = '^[A-Z]{2,4}[0-9]{4,8}[A-Z0-9]{0,2}$';

    public function up(Schema $schema): void
    {
        $table = $this->schema->createTable(self::TABLE_NAME);

        $table->addColumn('id', Types::GUID, ['notnull' => true]);

        $table->addColumn('code', Types::STRING, [
            'length' => self::CODE_MAX_LENGTH,
            'notnull' => true,
            'comment' => sprintf(
                'Product code: %d-%d chars, format: %s',
                self::CODE_MIN_LENGTH,
                self::CODE_MAX_LENGTH,
                self::CODE_REGEX
            )
        ]);

        $table->addColumn('name', Types::STRING, [
            'length' => 255,
            'notnull' => true
        ]);

        $table->addColumn('description', Types::TEXT, [
            'notnull' => false
        ]);

        $table->addColumn('price', Types::DECIMAL, [
            'precision' => 10,
            'scale' => 2,
            'notnull' => true
        ]);

        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['code'], 'uniq_products_code');

        $this->addCheckConstraint($table, sprintf(
            "LENGTH(code) >= %d AND LENGTH(code) <= %d AND code ~ '%s'",
            self::CODE_MIN_LENGTH,
            self::CODE_MAX_LENGTH,
            self::CODE_REGEX
        ), 'chk_product_code_format');
    }
}
