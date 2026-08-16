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
 * Somewhere to write things the customer must not read.
 *
 * The line description is the customer's line - it is printed on the invoice,
 * on the PDF and on the public link, exactly as typed. Anything a person needs
 * to remember about the line that is nobody else's business (which grades went
 * into it, which shipment it came off, what was agreed on the phone) had
 * nowhere to go, so it ended up in the description and went out on the invoice.
 *
 * This column is that somewhere. It is shown on the staff view only, and
 * carries no-print so it cannot leave on paper either.
 */
final class Version30000_43 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give an invoice line an internal note, so private wording stops ending up in the customer-facing description.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable('invoice_lines')) {
            return;
        }

        $table = $schema->getTable('invoice_lines');

        if (! $table->hasColumn('internal_note')) {
            $table->addColumn('internal_note', 'text', ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable('invoice_lines')) {
            return;
        }

        $table = $schema->getTable('invoice_lines');

        if ($table->hasColumn('internal_note')) {
            $table->dropColumn('internal_note');
        }
    }
}
