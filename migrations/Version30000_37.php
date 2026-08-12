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
use Doctrine\Migrations\AbstractMigration;
use SolidInvoice\CoreBundle\Entity\PurchaseItem;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\InvoiceBundle\Entity\Line;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Points invoice lines and purchase lines at a real stock item.
 *
 * Until now a line was free text matched to stock by name, which is exactly the
 * kind of guessing that goes wrong the moment two models are spelled slightly
 * differently. A line now carries the stock item's id, so "how many did we
 * sell" and "what is left" are answered from a hard link, not a string compare.
 *
 * Nullable on purpose: lines that are not stock (delivery, repair labour, a
 * one-off charge) simply have no stock item, and every line that already exists
 * stays exactly as it is.
 */
final class Version30000_37 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link invoice lines and purchase lines to a stock item.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable(StockModel::TABLE_NAME)) {
            return;
        }

        foreach ([Line::TABLE_NAME, PurchaseItem::TABLE_NAME] as $tableName) {
            if (! $schema->hasTable($tableName)) {
                continue;
            }

            $table = $schema->getTable($tableName);

            if ($table->hasColumn('stock_model_id')) {
                continue;
            }

            $table->addColumn('stock_model_id', UlidType::NAME, ['notnull' => false]);
            $table->addIndex(['stock_model_id'], 'idx_' . $tableName . '_stock_model');
            // Deleting a stock item must never take invoice history with it - the
            // line keeps its description and just loses the link.
            $table->addForeignKeyConstraint(
                StockModel::TABLE_NAME,
                ['stock_model_id'],
                ['id'],
                ['onDelete' => 'SET NULL']
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([Line::TABLE_NAME, PurchaseItem::TABLE_NAME] as $tableName) {
            if (! $schema->hasTable($tableName)) {
                continue;
            }

            $table = $schema->getTable($tableName);

            if ($table->hasColumn('stock_model_id')) {
                $table->dropColumn('stock_model_id');
            }
        }
    }
}
