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
use SolidInvoice\CoreBundle\Entity\ModelAlias;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Adds the model_alias table that maps typed-model-name variants to one
 * canonical model for the Sales-by-Model report.
 */
final class Version30000_24 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add model_alias table for merging model-name variants in the Sales-by-Model report.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(ModelAlias::TABLE_NAME)) {
            return;
        }

        $table = $schema->createTable(ModelAlias::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('alias', Types::STRING, ['length' => 255]);
        $table->addColumn('canonical', Types::STRING, ['length' => 255]);
        $table->addColumn('created', Types::DATETIME_MUTABLE);
        $table->addColumn('updated', Types::DATETIME_MUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['company_id', 'alias'], 'uniq_model_alias_company_alias');
        $table->addIndex(['company_id'], 'idx_model_alias_company');
        $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_model_alias_company');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(ModelAlias::TABLE_NAME)) {
            $schema->dropTable(ModelAlias::TABLE_NAME);
        }
    }
}
