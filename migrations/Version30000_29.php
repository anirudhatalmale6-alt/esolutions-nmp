<?php

declare(strict_types=1);

/*
 * This file is part of SolidInvoice project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen app_config.setting_value from TEXT (64 KB) to LONGTEXT. Image settings
 * (the company logo and the Marketplace listing logo) are stored here as base64
 * data; a small black-and-white invoice logo fits in TEXT, but a colour photo can
 * exceed 64 KB and get truncated/rejected, which made an uploaded colour listing
 * DP silently fall back to the invoice logo.
 */
final class Version30000_29 extends AbstractMigration
{
    private const TABLE = 'app_config';

    private const COLUMN = 'setting_value';

    public function getDescription(): string
    {
        return 'Widen app_config.setting_value to LONGTEXT so colour logos are not truncated.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->getTable(self::TABLE);

        if (! $table->hasColumn(self::COLUMN)) {
            return;
        }

        // A TEXT column with the maximum length makes Doctrine emit LONGTEXT.
        $table->getColumn(self::COLUMN)
            ->setType(\Doctrine\DBAL\Types\Type::getType(Types::TEXT))
            ->setLength(4294967295)
            ->setNotnull(false);
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->getTable(self::TABLE);

        if (! $table->hasColumn(self::COLUMN)) {
            return;
        }

        $table->getColumn(self::COLUMN)
            ->setLength(65535)
            ->setNotnull(false);
    }
}
