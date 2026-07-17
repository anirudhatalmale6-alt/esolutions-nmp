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
use SolidInvoice\CoreBundle\Entity\PurchasePayment;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Create the purchase_payment table so a purchase order can be paid in several
 * dated instalments, each landing on the correct day in the daily ledger.
 */
final class Version30000_21 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add purchase_payment table for dated supplier payments.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(PurchasePayment::TABLE_NAME)) {
            return;
        }

        $table = $schema->createTable(PurchasePayment::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('purchase_id', UlidType::NAME);
        $table->addColumn('payment_date', Types::DATE_MUTABLE);
        $table->addColumn('amount', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['purchase_id'], 'idx_purchase_payment_purchase');
        $table->addForeignKeyConstraint('purchase', ['purchase_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_purchase_payment_purchase');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(PurchasePayment::TABLE_NAME)) {
            $schema->dropTable(PurchasePayment::TABLE_NAME);
        }
    }
}
