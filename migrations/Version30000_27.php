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

/**
 * Adds country and city to marketplace_setting so each seller's location (with a
 * flag) can be shown in Marketplace search results.
 */
final class Version30000_27 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add country and city columns to marketplace_setting.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable(MarketplaceSetting::TABLE_NAME)) {
            return;
        }

        $table = $schema->getTable(MarketplaceSetting::TABLE_NAME);

        if (! $table->hasColumn('country')) {
            $table->addColumn('country', Types::STRING, ['length' => 2, 'notnull' => false]);
        }

        if (! $table->hasColumn('city')) {
            $table->addColumn('city', Types::STRING, ['length' => 100, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable(MarketplaceSetting::TABLE_NAME)) {
            return;
        }

        $table = $schema->getTable(MarketplaceSetting::TABLE_NAME);

        foreach (['country', 'city'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }
}
