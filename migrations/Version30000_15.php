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
use SolidInvoice\CoreBundle\Entity\Purchase;
use SolidInvoice\CoreBundle\Entity\Supplier;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Adds the purchase table (supplier bills / payables).
 */
final class Version30000_15 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add purchase table for supplier bills and payables.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(Purchase::TABLE_NAME)) {
            return;
        }

        $purchase = $schema->createTable(Purchase::TABLE_NAME);
        $purchase->addColumn('id', UlidType::NAME);
        $purchase->addColumn('company_id', UlidType::NAME);
        $purchase->addColumn('supplier_id', UlidType::NAME);
        $purchase->addColumn('reference', Types::STRING, ['length' => 128, 'notnull' => false]);
        $purchase->addColumn('purchase_date', Types::DATE_MUTABLE);
        $purchase->addColumn('description', Types::TEXT, ['notnull' => false]);
        $purchase->addColumn('total_amount', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
        $purchase->addColumn('amount_paid', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
        $purchase->addColumn('created', Types::DATETIME_MUTABLE);
        $purchase->addColumn('updated', Types::DATETIME_MUTABLE);
        $purchase->setPrimaryKey(['id']);
        $purchase->addIndex(['company_id'], 'idx_purchase_company');
        $purchase->addIndex(['supplier_id'], 'idx_purchase_supplier');
        $purchase->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_purchase_company');
        $purchase->addForeignKeyConstraint(Supplier::TABLE_NAME, ['supplier_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_purchase_supplier');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(Purchase::TABLE_NAME)) {
            $schema->dropTable(Purchase::TABLE_NAME);
        }
    }
}
