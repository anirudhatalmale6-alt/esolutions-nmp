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
 * Add a nullable "imei" column to invoice_lines. Holds the IMEI number(s) of the
 * handset(s) sold on that line (comma-separated), captured on the invoice form
 * for internal warranty/claim tracking. Additive and idempotent.
 */
final class Version30000_30 extends AbstractMigration
{
    private const TABLE = 'invoice_lines';

    private const COLUMN = 'imei';

    public function getDescription(): string
    {
        return 'Add nullable imei column to invoice_lines for internal IMEI tracking.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->getTable(self::TABLE);

        if ($table->hasColumn(self::COLUMN)) {
            return;
        }

        $table->addColumn(self::COLUMN, Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
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

        $table->dropColumn(self::COLUMN);
    }
}
