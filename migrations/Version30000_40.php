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

use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Ulid;

/**
 * Records what is on the shelf today as the opening figure.
 *
 * Stock quantities up to now came from the Tally import, which simply wrote a
 * number - there is no history behind any of them. From here on the system
 * keeps count itself: an invoice takes units out, a purchase puts them in. For
 * that to hold together, every quantity has to be explainable, so each item's
 * current figure is written into the ledger as its opening stock.
 *
 * Nothing is added or taken away. After this runs each item holds exactly what
 * it held before; the only difference is that the number now has a row behind
 * it saying where it came from.
 *
 * Safe to run twice: an item that already has any movement recorded is left
 * alone.
 */
final class Version30000_40 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record current stock quantities as the opening figure in the stock ledger.';
    }

    public function up(Schema $schema): void
    {
        // Data only - the schema itself is untouched.
        $this->addSql('SELECT 1');
    }

    public function postUp(Schema $schema): void
    {
        if (! $schema->hasTable('stock_model') || ! $schema->hasTable('stock_movement')) {
            return;
        }

        $models = $this->connection->fetchAllAssociative(
            'SELECT m.id, m.quantity, m.company_id
             FROM stock_model m
             WHERE m.quantity <> 0
               AND NOT EXISTS (SELECT 1 FROM stock_movement mv WHERE mv.stock_model_id = m.id)'
        );

        if ($models === []) {
            return;
        }

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($models as $model) {
            $this->connection->executeStatement(
                'INSERT INTO stock_movement
                    (id, stock_model_id, stock_grade_id, quantity, reason, reference, source_type, source_id, note, moved_at, recorded_by, company_id, created, updated)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)',
                [
                    (new Ulid())->toBinary(),
                    $model['id'],
                    (int) $model['quantity'],
                    'baseline',
                    'Opening stock',
                    'tally_import',
                    'The quantity held when live stock tracking was switched on',
                    $today,
                    'System',
                    $model['company_id'],
                    $now,
                    $now,
                ],
                [
                    ParameterType::BINARY,
                    ParameterType::BINARY,
                    ParameterType::INTEGER,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::BINARY,
                    ParameterType::STRING,
                    ParameterType::STRING,
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SELECT 1');
    }

    public function postDown(Schema $schema): void
    {
        if (! $schema->hasTable('stock_movement')) {
            return;
        }

        // Only the opening rows this migration wrote, recognisable by their
        // note. Real movements from invoices and purchases are never touched.
        $this->connection->executeStatement(
            "DELETE FROM stock_movement
             WHERE reason = 'baseline'
               AND recorded_by = 'System'
               AND note = 'The quantity held when live stock tracking was switched on'"
        );
    }
}
