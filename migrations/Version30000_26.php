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
use SolidInvoice\CoreBundle\Entity\MarketplaceSetting;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Adds the marketplace_setting table: each company's opt-in flag and WhatsApp
 * number for the public Marketplace stock search.
 */
final class Version30000_26 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add marketplace_setting table for per-company Marketplace opt-in and WhatsApp number.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(MarketplaceSetting::TABLE_NAME)) {
            return;
        }

        $table = $schema->createTable(MarketplaceSetting::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('listed', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('whatsapp', Types::STRING, ['length' => 50, 'notnull' => false]);
        $table->addColumn('created', Types::DATETIME_MUTABLE);
        $table->addColumn('updated', Types::DATETIME_MUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['company_id'], 'uniq_marketplace_setting_company');
        $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_marketplace_setting_company');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(MarketplaceSetting::TABLE_NAME)) {
            $schema->dropTable(MarketplaceSetting::TABLE_NAME);
        }
    }
}
