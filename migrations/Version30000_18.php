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
use SolidInvoice\CoreBundle\Entity\Expense;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Adds the expense table (payouts / operating expenses) that feeds the daily
 * ledger "money out" figure.
 */
final class Version30000_18 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add expense table for payouts / operating expenses.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(Expense::TABLE_NAME)) {
            return;
        }

        $expense = $schema->createTable(Expense::TABLE_NAME);
        $expense->addColumn('id', UlidType::NAME);
        $expense->addColumn('company_id', UlidType::NAME);
        $expense->addColumn('expense_date', Types::DATE_MUTABLE);
        $expense->addColumn('category', Types::STRING, ['length' => 128]);
        $expense->addColumn('payee', Types::STRING, ['length' => 191, 'notnull' => false]);
        $expense->addColumn('amount', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
        $expense->addColumn('description', Types::TEXT, ['notnull' => false]);
        $expense->addColumn('created', Types::DATETIME_MUTABLE);
        $expense->addColumn('updated', Types::DATETIME_MUTABLE);
        $expense->setPrimaryKey(['id']);
        $expense->addIndex(['company_id'], 'idx_expense_company');
        $expense->addIndex(['expense_date'], 'idx_expense_date');
        $expense->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_expense_company');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(Expense::TABLE_NAME)) {
            $schema->dropTable(Expense::TABLE_NAME);
        }
    }
}
