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
use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Adds the stock_model and stock_grade tables backing the Tally stock importer.
 */
final class Version30000_13 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stock_model and stock_grade tables for the Tally stock importer.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable(StockModel::TABLE_NAME)) {
            $model = $schema->createTable(StockModel::TABLE_NAME);
            $model->addColumn('id', UlidType::NAME);
            $model->addColumn('company_id', UlidType::NAME);
            $model->addColumn('name', Types::STRING, ['length' => 255]);
            $model->addColumn('quantity', Types::INTEGER);
            $model->addColumn('rate', Types::DECIMAL, ['precision' => 15, 'scale' => 4]);
            $model->addColumn('value', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
            $model->addColumn('created', Types::DATETIME_MUTABLE);
            $model->addColumn('updated', Types::DATETIME_MUTABLE);
            $model->setPrimaryKey(['id']);
            $model->addIndex(['company_id'], 'idx_stock_model_company');
            $model->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_stock_model_company');
        }

        if (! $schema->hasTable(StockGrade::TABLE_NAME)) {
            $grade = $schema->createTable(StockGrade::TABLE_NAME);
            $grade->addColumn('id', UlidType::NAME);
            $grade->addColumn('stock_model_id', UlidType::NAME);
            $grade->addColumn('grade', Types::STRING, ['length' => 255]);
            $grade->addColumn('quantity', Types::INTEGER);
            $grade->addColumn('rate', Types::DECIMAL, ['precision' => 15, 'scale' => 4]);
            $grade->addColumn('value', Types::DECIMAL, ['precision' => 15, 'scale' => 2]);
            $grade->setPrimaryKey(['id']);
            $grade->addIndex(['stock_model_id'], 'idx_stock_grade_model');
            $grade->addForeignKeyConstraint(StockModel::TABLE_NAME, ['stock_model_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_stock_grade_model');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(StockGrade::TABLE_NAME)) {
            $schema->dropTable(StockGrade::TABLE_NAME);
        }

        if ($schema->hasTable(StockModel::TABLE_NAME)) {
            $schema->dropTable(StockModel::TABLE_NAME);
        }
    }
}
