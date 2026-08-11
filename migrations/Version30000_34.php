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

/**
 * Customer opening balances. Adds clients.opening_balance so the old Tally
 * ledger (what a customer already owed before they were invoiced in
 * B2B Network) can be carried over and shown on the debtors report.
 *
 * Positive = the customer owes us (a Tally Debit balance).
 * Negative = we owe the customer / they are paid in advance (a Credit balance).
 */
final class Version30000_34 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add opening_balance to clients for carried-over Tally debtor balances.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable('clients')) {
            return;
        }

        $clients = $schema->getTable('clients');

        if (! $clients->hasColumn('opening_balance')) {
            $clients->addColumn('opening_balance', Types::DECIMAL, [
                'precision' => 15,
                'scale' => 2,
                'notnull' => true,
                'default' => '0.00',
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable('clients')) {
            return;
        }

        $clients = $schema->getTable('clients');

        if ($clients->hasColumn('opening_balance')) {
            $clients->dropColumn('opening_balance');
        }
    }
}
