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
use function array_keys;
use function sprintf;
use function strtolower;

/**
 * Record WHICH channel an account confirmed itself on.
 *
 * users.verified answers "is this account activated" and nothing more. The
 * confirmation link goes out twice now - once by email, once over WhatsApp - so
 * a single flag set by whichever link was opened cannot tell the owner what he
 * actually wants to know before letting a stranger trade: does that phone
 * number answer, and does that email address.
 *
 * Deliberately NOT back-filled. Every account confirmed before today opened a
 * link that was identical in both messages, so which one they used was never
 * recorded and cannot now be worked out. Writing a date into either column
 * would put a tick against an address or a number that may never have been
 * touched, which is worse than an honest blank - the Users page shows those
 * accounts as confirmed with no channel recorded.
 */
final class Version30000_50 extends AbstractMigration
{
    private const TABLE = 'users';

    /**
     * @var array<string, string>
     */
    private const COLUMNS = [
        'email_verified_at' => 'DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'',
        'mobile_verified_at' => 'DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'',
    ];

    public function getDescription(): string
    {
        return 'Record whether an account confirmed its email address, its WhatsApp number, or both.';
    }

    public function up(Schema $schema): void
    {
        $existing = $this->existingColumns();

        foreach (self::COLUMNS as $column => $definition) {
            // Re-runnable: an install whose schema was built from the entities
            // rather than from the migrations already has these.
            if (isset($existing[$column])) {
                continue;
            }

            $this->addSql(sprintf('ALTER TABLE %s ADD %s %s', self::TABLE, $column, $definition));
        }
    }

    public function down(Schema $schema): void
    {
        $existing = $this->existingColumns();

        foreach (array_keys(self::COLUMNS) as $column) {
            if (! isset($existing[$column])) {
                continue;
            }

            $this->addSql(sprintf('ALTER TABLE %s DROP %s', self::TABLE, $column));
        }
    }

    /**
     * @return array<string, true>
     */
    private function existingColumns(): array
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (! $schemaManager->tablesExist([self::TABLE])) {
            return [];
        }

        $columns = [];

        foreach ($schemaManager->listTableColumns(self::TABLE) as $column) {
            $columns[strtolower($column->getName())] = true;
        }

        return $columns;
    }
}
