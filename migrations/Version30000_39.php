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
 * Lets the platform owner hand a business the Marketplace by name.
 *
 * The Marketplace was Premium-only, so letting one business in meant moving it
 * onto a plan it had not bought. This is a separate grant: it opens the
 * Marketplace and nothing else, leaves the plan and its expiry alone, and can be
 * taken back the same way it was given.
 *
 * Starts off for everyone. Businesses already on Premium are unaffected - they
 * reach the Marketplace through their plan exactly as before.
 */
final class Version30000_39 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow the Marketplace to be enabled per business without Premium.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable('companies')) {
            return;
        }

        $table = $schema->getTable('companies');

        if ($table->hasColumn('marketplace_access')) {
            return;
        }

        $table->addColumn('marketplace_access', 'boolean', ['notnull' => true, 'default' => 0]);
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable('companies')) {
            return;
        }

        $table = $schema->getTable('companies');

        if ($table->hasColumn('marketplace_access')) {
            $table->dropColumn('marketplace_access');
        }
    }
}
