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

/**
 * Stock at grade level, and a switch that decides whether it is live.
 *
 * Two things go in together because they are the same piece of work.
 *
 * Grade, because a handset is not one number. A hundred Samsung S22 arriving
 * from Hong Kong are sixty Grade A and forty Grade B - priced differently, sold
 * separately, and regraded between one another when a unit turns out worse than
 * it was booked as. Invoice and purchase lines therefore point at a grade, not
 * just at the item.
 *
 * The switch, because turning stock live changes how a business's day works.
 * It is off for everyone here, so this migration changes nothing about how the
 * system behaves: quantities keep moving only when somebody imports from Tally.
 * Each business turns it on when it is ready, which also lets a test business be
 * switched on and played with while the real one carries on untouched.
 */
final class Version30000_41 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track stock per grade on invoice and purchase lines, and add the per-business live stock switch.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('companies')) {
            $companies = $schema->getTable('companies');

            if (! $companies->hasColumn('live_stock')) {
                $companies->addColumn('live_stock', 'boolean', ['notnull' => true, 'default' => 0]);
            }
        }

        foreach (['invoice_lines', 'purchase_item'] as $tableName) {
            if (! $schema->hasTable($tableName)) {
                continue;
            }

            $table = $schema->getTable($tableName);

            if ($table->hasColumn('stock_grade_id')) {
                continue;
            }

            $table->addColumn('stock_grade_id', 'binary', ['length' => 16, 'notnull' => false, 'fixed' => true]);
            $table->addIndex(['stock_grade_id'], 'idx_' . $tableName . '_stock_grade');

            // SET NULL rather than CASCADE: if a grade ever does disappear, the
            // line and its money must survive - it simply stops being linked.
            if ($schema->hasTable('stock_grade')) {
                $table->addForeignKeyConstraint('stock_grade', ['stock_grade_id'], ['id'], ['onDelete' => 'SET NULL']);
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('companies')) {
            $companies = $schema->getTable('companies');

            if ($companies->hasColumn('live_stock')) {
                $companies->dropColumn('live_stock');
            }
        }

        foreach (['invoice_lines', 'purchase_item'] as $tableName) {
            if (! $schema->hasTable($tableName)) {
                continue;
            }

            $table = $schema->getTable($tableName);

            if ($table->hasColumn('stock_grade_id')) {
                $table->dropColumn('stock_grade_id');
            }
        }
    }
}
