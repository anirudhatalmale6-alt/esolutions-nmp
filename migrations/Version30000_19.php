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
use SolidInvoice\ClientBundle\Entity\Client;

/**
 * Adds a saved WhatsApp / mobile number to the client, used to send invoices
 * and quotes straight to the buyer's chat with one tap.
 */
final class Version30000_19 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add whatsapp number column to clients.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(Client::TABLE_NAME);

        if (! $table->hasColumn('whatsapp')) {
            $table->addColumn('whatsapp', Types::STRING, ['length' => 35, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(Client::TABLE_NAME);

        if ($table->hasColumn('whatsapp')) {
            $table->dropColumn('whatsapp');
        }
    }
}
