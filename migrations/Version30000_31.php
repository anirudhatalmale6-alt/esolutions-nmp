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
 * Membership/subscription backbone: adds the plan, expiry, complimentary and
 * verified columns to the companies table.
 *
 * Grandfathering: every company that already exists when this runs is given a
 * permanent, complimentary Premium membership and marked verified, so the live
 * platform owner (and any existing vendor) keeps full, uninterrupted access.
 * Companies created AFTER this migration start on 'none' and must be activated
 * (paid or comped). Additive and idempotent.
 */
final class Version30000_31 extends AbstractMigration
{
    private const TABLE = 'companies';

    public function getDescription(): string
    {
        return 'Add membership plan/expiry/complimentary/verified columns to companies (grandfather existing companies to complimentary Premium).';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->getTable(self::TABLE);

        if (! $table->hasColumn('membership_plan')) {
            $table->addColumn('membership_plan', Types::STRING, [
                'length' => 20,
                'notnull' => true,
                'default' => 'none',
            ]);
        }

        if (! $table->hasColumn('membership_expires_at')) {
            $table->addColumn('membership_expires_at', Types::DATETIME_IMMUTABLE, [
                'notnull' => false,
                'default' => null,
            ]);
        }

        if (! $table->hasColumn('membership_complimentary')) {
            $table->addColumn('membership_complimentary', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
        }

        if (! $table->hasColumn('verified')) {
            $table->addColumn('verified', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
        }
    }

    /**
     * Runs after the columns exist. Grandfathers every pre-existing company to a
     * complimentary, non-expiring Premium so nobody loses access the moment the
     * gate switches on. Only touches rows still on the freshly-defaulted 'none'.
     */
    public function postUp(Schema $schema): void
    {
        if (! $schema->hasTable(self::TABLE)) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE companies SET membership_plan = ?, membership_complimentary = ?, verified = ?, membership_expires_at = NULL WHERE membership_plan = ?',
            ['premium', true, true, 'none'],
            [Types::STRING, Types::BOOLEAN, Types::BOOLEAN, Types::STRING],
        );
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->getTable(self::TABLE);

        foreach (['membership_plan', 'membership_expires_at', 'membership_complimentary', 'verified'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }
}
