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
 * A way to say "this is broken" from inside the product.
 *
 * There was none. Reporting a problem meant already having the platform owner's
 * phone number, which works while everybody knows each other and stops working
 * on the day somebody joins who does not.
 *
 * Two tables: the ticket, and the messages on it. Both hold a copy of who wrote
 * them as plain text alongside the relation, so a conversation still reads
 * properly after an account has been deleted - the foreign keys are ON DELETE
 * SET NULL for the same reason.
 */
final class Version30000_45 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the support desk - tickets from members to the platform owner, and the replies on them.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable('support_ticket')) {
            $table = $schema->createTable('support_ticket');
            $table->addColumn('id', UlidType::NAME, ['notnull' => true]);
            $table->addColumn('company_id', UlidType::NAME, ['notnull' => false]);
            $table->addColumn('raised_by_id', UlidType::NAME, ['notnull' => false]);
            $table->addColumn('raised_by_name', Types::STRING, ['length' => 191, 'notnull' => false]);
            $table->addColumn('raised_by_email', Types::STRING, ['length' => 191, 'notnull' => false]);
            $table->addColumn('subject', Types::STRING, ['length' => 191, 'notnull' => true]);
            $table->addColumn('kind', Types::STRING, ['length' => 20, 'notnull' => true, 'default' => 'problem']);
            $table->addColumn('status', Types::STRING, ['length' => 20, 'notnull' => true, 'default' => 'open']);
            $table->addColumn('awaiting_owner', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
            $table->addColumn('unread_by_member', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('last_message_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);
            $table->addColumn('updated', Types::DATETIME_MUTABLE, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['status'], 'support_ticket_status_idx');
            $table->addIndex(['awaiting_owner'], 'support_ticket_awaiting_idx');
            $table->addIndex(['company_id'], 'support_ticket_company_idx');

            if ($schema->hasTable('companies')) {
                $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_support_ticket_company');
            }

            if ($schema->hasTable('users')) {
                $table->addForeignKeyConstraint('users', ['raised_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_support_ticket_user');
            }
        }

        if (! $schema->hasTable('support_message')) {
            $table = $schema->createTable('support_message');
            $table->addColumn('id', UlidType::NAME, ['notnull' => true]);
            $table->addColumn('ticket_id', UlidType::NAME, ['notnull' => true]);
            $table->addColumn('author_id', UlidType::NAME, ['notnull' => false]);
            $table->addColumn('author_name', Types::STRING, ['length' => 191, 'notnull' => false]);
            $table->addColumn('from_owner', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('body', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);
            $table->addColumn('updated', Types::DATETIME_MUTABLE, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['ticket_id'], 'support_message_ticket_idx');
            $table->addForeignKeyConstraint('support_ticket', ['ticket_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_support_message_ticket');

            if ($schema->hasTable('users')) {
                $table->addForeignKeyConstraint('users', ['author_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_support_message_user');
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Messages first - the foreign key points that way.
        if ($schema->hasTable('support_message')) {
            $schema->dropTable('support_message');
        }

        if ($schema->hasTable('support_ticket')) {
            $schema->dropTable('support_ticket');
        }
    }
}
