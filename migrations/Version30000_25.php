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
use SolidInvoice\CoreBundle\Entity\ModelCatalogEntry;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Adds the model_catalog table: each company's editable list of phone-model
 * names that feeds the line-item model suggestion box.
 */
final class Version30000_25 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add model_catalog table for the per-company editable phone-model suggestion list.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(ModelCatalogEntry::TABLE_NAME)) {
            return;
        }

        $table = $schema->createTable(ModelCatalogEntry::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('name', Types::STRING, ['length' => 255]);
        $table->addColumn('created', Types::DATETIME_MUTABLE);
        $table->addColumn('updated', Types::DATETIME_MUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['company_id', 'name'], 'uniq_model_catalog_company_name');
        $table->addIndex(['company_id'], 'idx_model_catalog_company');
        $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_model_catalog_company');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(ModelCatalogEntry::TABLE_NAME)) {
            $schema->dropTable(ModelCatalogEntry::TABLE_NAME);
        }
    }
}
