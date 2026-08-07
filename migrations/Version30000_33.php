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
use SolidInvoice\CoreBundle\Entity\ReferralLink;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Referral / sales invite links. Adds the referral_link table (a named join code
 * per sales rep) and stamps each company with the code + rep name that referred it,
 * so open public registration can be closed and every signup attributed to a rep.
 */
final class Version30000_33 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add referral_link table and referral attribution columns on companies.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable(ReferralLink::TABLE_NAME)) {
            $table = $schema->createTable(ReferralLink::TABLE_NAME);
            $table->addColumn('id', UlidType::NAME);
            $table->addColumn('code', Types::STRING, ['length' => 64]);
            $table->addColumn('rep_name', Types::STRING, ['length' => 191]);
            $table->addColumn('active', Types::BOOLEAN, ['default' => true]);
            $table->addColumn('note', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created', Types::DATETIME_MUTABLE);
            $table->addColumn('updated', Types::DATETIME_MUTABLE);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['code'], 'uniq_referral_link_code');
        }

        if ($schema->hasTable('companies')) {
            $companies = $schema->getTable('companies');

            if (! $companies->hasColumn('referred_by_code')) {
                $companies->addColumn('referred_by_code', Types::STRING, ['length' => 64, 'notnull' => false]);
                $companies->addIndex(['referred_by_code'], 'idx_companies_referred_by_code');
            }

            if (! $companies->hasColumn('referred_by_name')) {
                $companies->addColumn('referred_by_name', Types::STRING, ['length' => 191, 'notnull' => false]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(ReferralLink::TABLE_NAME)) {
            $schema->dropTable(ReferralLink::TABLE_NAME);
        }

        if ($schema->hasTable('companies')) {
            $companies = $schema->getTable('companies');

            if ($companies->hasColumn('referred_by_code')) {
                $companies->dropColumn('referred_by_code');
            }

            if ($companies->hasColumn('referred_by_name')) {
                $companies->dropColumn('referred_by_name');
            }
        }
    }
}
