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
use SolidInvoice\CoreBundle\Entity\CreditNote;

/**
 * Add returned_lines to credit_note so a refund can record HOW MANY units of each
 * invoice line came back. Stored as a JSON map of invoice_line id => qty returned.
 * Used to show the returned units (in red) and the net qty on the invoice, without
 * ever rewriting the original invoice line.
 */
final class Version30000_22 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add returned_lines JSON column to credit_note (per-line returned qty).';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable(CreditNote::TABLE_NAME)) {
            return;
        }

        $table = $schema->getTable(CreditNote::TABLE_NAME);

        if (! $table->hasColumn('returned_lines')) {
            $table->addColumn('returned_lines', Types::JSON, ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable(CreditNote::TABLE_NAME)) {
            return;
        }

        $table = $schema->getTable(CreditNote::TABLE_NAME);

        if ($table->hasColumn('returned_lines')) {
            $table->dropColumn('returned_lines');
        }
    }
}
