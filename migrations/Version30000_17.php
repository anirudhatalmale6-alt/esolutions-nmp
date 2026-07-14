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
use SolidInvoice\CoreBundle\Entity\PurchaseItem;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Adds the purchase_item table so purchase orders can be itemised with line items
 * (one row per purchased product), the same way invoices are.
 */
final class Version30000_17 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add purchase_item table for itemised purchase orders.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(PurchaseItem::TABLE_NAME)) {
            return;
        }

        $item = $schema->createTable(PurchaseItem::TABLE_NAME);
        $item->addColumn('id', UlidType::NAME);
        $item->addColumn('purchase_id', UlidType::NAME);
        $item->addColumn('description', Types::STRING, ['length' => 255]);
        $item->addColumn('qty', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
        $item->addColumn('price', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
        $item->addColumn('total', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
        $item->setPrimaryKey(['id']);
        $item->addIndex(['purchase_id'], 'idx_purchase_item_purchase');
        $item->addForeignKeyConstraint(Purchase::TABLE_NAME, ['purchase_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_purchase_item_purchase');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(PurchaseItem::TABLE_NAME)) {
            $schema->dropTable(PurchaseItem::TABLE_NAME);
        }
    }
}
