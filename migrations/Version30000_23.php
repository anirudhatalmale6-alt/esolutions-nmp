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
use SolidInvoice\CoreBundle\Entity\UnlockCode;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Adds the unlock_code table backing the IMEI SIM-unlock code lookup: one row
 * per IMEI with its code (or status such as "SIM Free" / "Locked").
 */
final class Version30000_23 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unlock_code table for the IMEI unlock-code lookup.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(UnlockCode::TABLE_NAME)) {
            return;
        }

        $table = $schema->createTable(UnlockCode::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('imei', Types::STRING, ['length' => 32]);
        $table->addColumn('code', Types::STRING, ['length' => 255]);
        $table->addColumn('created', Types::DATETIME_MUTABLE);
        $table->addColumn('updated', Types::DATETIME_MUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['company_id', 'imei'], 'uniq_unlock_company_imei');
        $table->addIndex(['imei'], 'idx_unlock_imei');
        $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_unlock_company');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(UnlockCode::TABLE_NAME)) {
            $schema->dropTable(UnlockCode::TABLE_NAME);
        }
    }
}
