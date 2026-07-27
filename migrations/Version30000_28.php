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
use SolidInvoice\CoreBundle\Entity\SharedModelCatalogEntry;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Adds the model_catalog_shared table: a single portal-wide phone-model list that
 * every vendor's line-item suggestion box reads from. Not company-scoped, so a
 * list curated once by the platform owner helps every vendor. Only the Super User
 * can edit it.
 */
final class Version30000_28 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add model_catalog_shared table for the portal-wide shared phone-model suggestion list.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(SharedModelCatalogEntry::TABLE_NAME)) {
            return;
        }

        $table = $schema->createTable(SharedModelCatalogEntry::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('name', Types::STRING, ['length' => 255]);
        $table->addColumn('created', Types::DATETIME_MUTABLE);
        $table->addColumn('updated', Types::DATETIME_MUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['name'], 'uniq_shared_model_catalog_name');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(SharedModelCatalogEntry::TABLE_NAME)) {
            $schema->dropTable(SharedModelCatalogEntry::TABLE_NAME);
        }
    }
}
