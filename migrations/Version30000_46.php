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
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * The Marketplace becomes a place people come back to.
 *
 * Until now it was a search box: you looked something up and left. This adds the
 * two things that keep people on it - four paid classified adverts the platform
 * owner sells, and a community feed where any member posts stock and anybody
 * else answers underneath.
 *
 * Every foreign key here is ON DELETE SET NULL except a reply's link to its
 * post, which cascades. A post has to outlive the account that wrote it, or a
 * conversation loses half of itself the day somebody leaves; a reply with no
 * post to sit under is not worth keeping.
 */
final class Version30000_46 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Marketplace classified adverts and the community feed, plus the per-company Classifieds switch.';
    }

    public function up(Schema $schema): void
    {
        // The switch the platform owner sells a place with. Guarded because a
        // site that has been through an earlier partial run must not fail here.
        if ($schema->hasTable('companies')) {
            $companies = $schema->getTable('companies');

            if (! $companies->hasColumn('classifieds_access')) {
                $companies->addColumn('classifieds_access', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            }
        }

        if (! $schema->hasTable('marketplace_ad')) {
            $table = $schema->createTable('marketplace_ad');
            $table->addColumn('id', UlidType::NAME, ['notnull' => true]);
            $table->addColumn('company_id', UlidType::NAME, ['notnull' => false]);
            $table->addColumn('created_by_id', UlidType::NAME, ['notnull' => false]);
            $table->addColumn('business_name', Types::STRING, ['length' => 191, 'notnull' => false]);
            $table->addColumn('title', Types::STRING, ['length' => 120, 'notnull' => true]);
            $table->addColumn('caption', Types::STRING, ['length' => 255, 'notnull' => false]);
            $table->addColumn('image_path', Types::STRING, ['length' => 255, 'notnull' => false]);
            $table->addColumn('link_url', Types::STRING, ['length' => 255, 'notnull' => false]);
            $table->addColumn('slot', Types::SMALLINT, ['notnull' => false]);
            $table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
            $table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);
            $table->addColumn('updated', Types::DATETIME_MUTABLE, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['slot'], 'marketplace_ad_slot_idx');
            $table->addIndex(['company_id'], 'marketplace_ad_company_idx');

            if ($schema->hasTable('companies')) {
                $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_marketplace_ad_company');
            }

            if ($schema->hasTable('users')) {
                $table->addForeignKeyConstraint('users', ['created_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_marketplace_ad_user');
            }
        }

        if (! $schema->hasTable('community_post')) {
            $table = $schema->createTable('community_post');
            $table->addColumn('id', UlidType::NAME, ['notnull' => true]);
            $table->addColumn('company_id', UlidType::NAME, ['notnull' => false]);
            $table->addColumn('author_id', UlidType::NAME, ['notnull' => false]);
            $table->addColumn('author_name', Types::STRING, ['length' => 191, 'notnull' => false]);
            $table->addColumn('business_name', Types::STRING, ['length' => 191, 'notnull' => false]);
            $table->addColumn('body', Types::TEXT, ['notnull' => true]);
            $table->addColumn('images', Types::JSON, ['notnull' => true]);
            $table->addColumn('hidden', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('last_activity_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);
            $table->addColumn('updated', Types::DATETIME_MUTABLE, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['hidden'], 'community_post_hidden_idx');
            $table->addIndex(['company_id'], 'community_post_company_idx');

            if ($schema->hasTable('companies')) {
                $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_community_post_company');
            }

            if ($schema->hasTable('users')) {
                $table->addForeignKeyConstraint('users', ['author_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_community_post_user');
            }
        }

        if (! $schema->hasTable('community_comment')) {
            $table = $schema->createTable('community_comment');
            $table->addColumn('id', UlidType::NAME, ['notnull' => true]);
            $table->addColumn('post_id', UlidType::NAME, ['notnull' => true]);
            $table->addColumn('company_id', UlidType::NAME, ['notnull' => false]);
            $table->addColumn('author_id', UlidType::NAME, ['notnull' => false]);
            $table->addColumn('author_name', Types::STRING, ['length' => 191, 'notnull' => false]);
            $table->addColumn('business_name', Types::STRING, ['length' => 191, 'notnull' => false]);
            $table->addColumn('body', Types::TEXT, ['notnull' => true]);
            $table->addColumn('hidden', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);
            $table->addColumn('updated', Types::DATETIME_MUTABLE, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['post_id'], 'community_comment_post_idx');
            $table->addForeignKeyConstraint('community_post', ['post_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_community_comment_post');

            if ($schema->hasTable('companies')) {
                $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_community_comment_company');
            }

            if ($schema->hasTable('users')) {
                $table->addForeignKeyConstraint('users', ['author_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_community_comment_user');
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Replies first - the foreign key points that way.
        if ($schema->hasTable('community_comment')) {
            $schema->dropTable('community_comment');
        }

        if ($schema->hasTable('community_post')) {
            $schema->dropTable('community_post');
        }

        if ($schema->hasTable('marketplace_ad')) {
            $schema->dropTable('marketplace_ad');
        }

        if ($schema->hasTable('companies')) {
            $companies = $schema->getTable('companies');

            if ($companies->hasColumn('classifieds_access')) {
                $companies->dropColumn('classifieds_access');
            }
        }
    }
}
