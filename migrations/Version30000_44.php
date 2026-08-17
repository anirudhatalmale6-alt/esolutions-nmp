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
 * Something to judge a business on before trusting it.
 *
 * The Verified tick already existed, and Premium already refuses to switch on
 * without it - but there was nothing behind it. The panel showed a name and an
 * email address, and the owner had to decide from that.
 *
 * These columns are what the second page of sign-up now collects (where they
 * trade from, the number to reach them on) plus the identity documents they can
 * send in afterwards for the trusted badge. The document columns hold paths
 * under var/verification, never under public - see VerificationDocument.
 */
final class Version30000_44 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give a company a real profile - city, country, contact number and identity documents - so the trusted badge has something behind it.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable('companies')) {
            return;
        }

        $table = $schema->getTable('companies');

        if (! $table->hasColumn('city')) {
            $table->addColumn('city', Types::STRING, ['length' => 100, 'notnull' => false, 'default' => null]);
        }

        if (! $table->hasColumn('country')) {
            $table->addColumn('country', Types::STRING, ['length' => 2, 'notnull' => false, 'default' => null]);
        }

        if (! $table->hasColumn('contact_number')) {
            $table->addColumn('contact_number', Types::STRING, ['length' => 32, 'notnull' => false, 'default' => null]);
        }

        if (! $table->hasColumn('contact_verified')) {
            $table->addColumn('contact_verified', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        }

        foreach (['id_front_path', 'id_back_path', 'passport_path'] as $column) {
            if (! $table->hasColumn($column)) {
                $table->addColumn($column, Types::STRING, ['length' => 255, 'notnull' => false, 'default' => null]);
            }
        }

        if (! $table->hasColumn('verification_submitted_at')) {
            $table->addColumn('verification_submitted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false, 'default' => null]);
        }
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable('companies')) {
            return;
        }

        $table = $schema->getTable('companies');

        foreach ([
            'city',
            'country',
            'contact_number',
            'contact_verified',
            'id_front_path',
            'id_back_path',
            'passport_path',
            'verification_submitted_at',
        ] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }
}
