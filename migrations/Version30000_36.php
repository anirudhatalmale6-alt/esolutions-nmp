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
use SolidInvoice\CoreBundle\Entity\DailyNote;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * The daily scrap note shown on the daily ledger - one free-text note per
 * company per day, replacing the paper pad.
 */
final class Version30000_36 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add daily_note table for the daily ledger scrap notes.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(DailyNote::TABLE_NAME)) {
            return;
        }

        $table = $schema->createTable(DailyNote::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('note_date', Types::DATE_MUTABLE);
        $table->addColumn('body', Types::TEXT, ['notnull' => false]);
        $table->addColumn('updated_by', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('created', Types::DATETIME_MUTABLE);
        $table->addColumn('updated', Types::DATETIME_MUTABLE);
        $table->setPrimaryKey(['id']);

        // One note per company per day - saving again edits that day's page.
        $table->addUniqueIndex(['company_id', 'note_date'], 'uniq_daily_note_company_date');

        if ($schema->hasTable('companies')) {
            $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(DailyNote::TABLE_NAME)) {
            $schema->dropTable(DailyNote::TABLE_NAME);
        }
    }
}
