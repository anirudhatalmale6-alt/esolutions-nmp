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
 * One invoice line, more than one grade.
 *
 * Stock is not always sold a grade at a time. A hundred handsets go out as
 * sixty Grade A and forty Grade B, invoiced as one line for a hundred, because
 * the customer is buying a lot and was never offered a grade breakdown. The
 * line the customer sees stays exactly as it is; this column records what it
 * was really made of, so the quantity comes out of the right grades instead of
 * out of whichever one happened to be picked first.
 *
 * Nothing existing changes: an ordinary line selling a single grade leaves this
 * empty and carries on using stock_grade_id.
 */
final class Version30000_42 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let one invoice line be a mix of grades, recorded internally and kept off the customer copy.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable('invoice_lines')) {
            return;
        }

        $table = $schema->getTable('invoice_lines');

        if (! $table->hasColumn('grade_split')) {
            $table->addColumn('grade_split', 'json', ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable('invoice_lines')) {
            return;
        }

        $table = $schema->getTable('invoice_lines');

        if ($table->hasColumn('grade_split')) {
            $table->dropColumn('grade_split');
        }
    }
}
