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
use SolidInvoice\CoreBundle\Entity\StockMovement;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * The stock ledger: one row per change to a model's quantity, with the reason
 * and a pointer back to the document that caused it. This is the audit trail
 * behind the running quantity on stock_model, and what makes live stock (in on
 * purchase, out on invoice) traceable rather than a number that just moves.
 *
 * Additive only - nothing existing is altered, so deploying this on its own
 * changes no behaviour.
 */
final class Version30000_35 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stock_movement ledger table for live inventory tracking.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(StockMovement::TABLE_NAME)) {
            return;
        }

        $table = $schema->createTable(StockMovement::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('stock_model_id', UlidType::NAME);
        $table->addColumn('stock_grade_id', UlidType::NAME, ['notnull' => false]);
        $table->addColumn('quantity', Types::INTEGER);
        $table->addColumn('reason', Types::STRING, ['length' => 32]);
        $table->addColumn('reference', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('source_type', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('source_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('note', Types::TEXT, ['notnull' => false]);
        $table->addColumn('moved_at', Types::DATE_MUTABLE);
        $table->addColumn('recorded_by', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('created', Types::DATETIME_MUTABLE);
        $table->addColumn('updated', Types::DATETIME_MUTABLE);
        $table->setPrimaryKey(['id']);

        $table->addIndex(['stock_model_id'], 'idx_stock_movement_model');
        $table->addIndex(['company_id'], 'idx_stock_movement_company');
        $table->addIndex(['moved_at'], 'idx_stock_movement_moved_at');
        // Reversing a cancelled document looks movements up by their source.
        $table->addIndex(['source_type', 'source_id'], 'idx_stock_movement_source');

        if ($schema->hasTable('companies')) {
            $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        }

        if ($schema->hasTable('stock_model')) {
            $table->addForeignKeyConstraint('stock_model', ['stock_model_id'], ['id'], ['onDelete' => 'CASCADE']);
        }

        if ($schema->hasTable('stock_grade')) {
            $table->addForeignKeyConstraint('stock_grade', ['stock_grade_id'], ['id'], ['onDelete' => 'SET NULL']);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(StockMovement::TABLE_NAME)) {
            $schema->dropTable(StockMovement::TABLE_NAME);
        }
    }
}
