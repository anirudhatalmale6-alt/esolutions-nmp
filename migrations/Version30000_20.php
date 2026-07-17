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
use SolidInvoice\CoreBundle\Entity\CreditNote;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Create the credit_note table for customer refunds / credit notes raised against
 * an invoice (cash refund or store credit). Money + record only; stock stays with
 * the Tally import.
 */
final class Version30000_20 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add credit_note table for customer refunds / credit notes.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(CreditNote::TABLE_NAME)) {
            return;
        }

        $table = $schema->createTable(CreditNote::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('invoice_id', UlidType::NAME);
        $table->addColumn('client_id', UlidType::NAME);
        $table->addColumn('credit_date', Types::DATE_MUTABLE);
        $table->addColumn('amount', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
        $table->addColumn('refund_type', Types::STRING, ['length' => 16]);
        $table->addColumn('disposition', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('reference', Types::STRING, ['length' => 128, 'notnull' => false]);
        $table->addColumn('reason', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created', Types::DATETIME_MUTABLE);
        $table->addColumn('updated', Types::DATETIME_MUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['company_id'], 'idx_credit_note_company');
        $table->addIndex(['invoice_id'], 'idx_credit_note_invoice');
        $table->addIndex(['client_id'], 'idx_credit_note_client');
        $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_credit_note_company');
        $table->addForeignKeyConstraint('invoices', ['invoice_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_credit_note_invoice');
        $table->addForeignKeyConstraint('clients', ['client_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_credit_note_client');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(CreditNote::TABLE_NAME)) {
            $schema->dropTable(CreditNote::TABLE_NAME);
        }
    }
}
