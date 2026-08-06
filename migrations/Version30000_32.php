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
use SolidInvoice\CoreBundle\Entity\CustomerReceipt;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Create the customer_receipt table: standalone "money in" payments received from
 * a customer that are not tied to a specific B2B invoice (a debtor clearing an old
 * balance, or cash over the counter). Feeds the daily ledger money-in and reduces
 * the customer's outstanding balance.
 */
final class Version30000_32 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customer_receipt table for standalone customer payments (money in).';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(CustomerReceipt::TABLE_NAME)) {
            return;
        }

        $table = $schema->createTable(CustomerReceipt::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('client_id', UlidType::NAME, ['notnull' => false]);
        $table->addColumn('payer_name', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('receipt_date', Types::DATE_MUTABLE);
        $table->addColumn('amount', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
        $table->addColumn('method', Types::STRING, ['length' => 32]);
        $table->addColumn('reference', Types::STRING, ['length' => 128, 'notnull' => false]);
        $table->addColumn('note', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created', Types::DATETIME_MUTABLE);
        $table->addColumn('updated', Types::DATETIME_MUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['company_id'], 'idx_customer_receipt_company');
        $table->addIndex(['client_id'], 'idx_customer_receipt_client');
        $table->addIndex(['receipt_date'], 'idx_customer_receipt_date');
        $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_customer_receipt_company');
        $table->addForeignKeyConstraint('clients', ['client_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_customer_receipt_client');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(CustomerReceipt::TABLE_NAME)) {
            $schema->dropTable(CustomerReceipt::TABLE_NAME);
        }
    }
}
