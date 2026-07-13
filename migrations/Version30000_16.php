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
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Point purchases at the existing client list instead of a separate supplier.
 * The purchase table is rebuilt with client_id in place of supplier_id (it holds
 * no data yet). The app's schema updater recreates it from entity metadata once
 * the old empty table is dropped; this also builds it for the standard migrator.
 */
final class Version30000_16 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchases now reference clients (client_id) instead of suppliers.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(Purchase::TABLE_NAME)) {
            $schema->dropTable(Purchase::TABLE_NAME);
        }

        $purchase = $schema->createTable(Purchase::TABLE_NAME);
        $purchase->addColumn('id', UlidType::NAME);
        $purchase->addColumn('company_id', UlidType::NAME);
        $purchase->addColumn('client_id', UlidType::NAME);
        $purchase->addColumn('reference', Types::STRING, ['length' => 128, 'notnull' => false]);
        $purchase->addColumn('purchase_date', Types::DATE_MUTABLE);
        $purchase->addColumn('description', Types::TEXT, ['notnull' => false]);
        $purchase->addColumn('total_amount', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
        $purchase->addColumn('amount_paid', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
        $purchase->addColumn('created', Types::DATETIME_MUTABLE);
        $purchase->addColumn('updated', Types::DATETIME_MUTABLE);
        $purchase->setPrimaryKey(['id']);
        $purchase->addIndex(['company_id'], 'idx_purchase_company');
        $purchase->addIndex(['client_id'], 'idx_purchase_client');
        $purchase->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_purchase_company');
        $purchase->addForeignKeyConstraint('clients', ['client_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_purchase_client');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(Purchase::TABLE_NAME)) {
            $schema->dropTable(Purchase::TABLE_NAME);
        }
    }
}
