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
use SolidInvoice\CoreBundle\Entity\Supplier;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Adds the supplier table for the supplier module.
 */
final class Version30000_14 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add supplier table for the supplier module.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(Supplier::TABLE_NAME)) {
            return;
        }

        $supplier = $schema->createTable(Supplier::TABLE_NAME);
        $supplier->addColumn('id', UlidType::NAME);
        $supplier->addColumn('company_id', UlidType::NAME);
        $supplier->addColumn('name', Types::STRING, ['length' => 255]);
        $supplier->addColumn('contact_person', Types::STRING, ['length' => 255, 'notnull' => false]);
        $supplier->addColumn('email', Types::STRING, ['length' => 255, 'notnull' => false]);
        $supplier->addColumn('phone', Types::STRING, ['length' => 64, 'notnull' => false]);
        $supplier->addColumn('tax_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $supplier->addColumn('address', Types::TEXT, ['notnull' => false]);
        $supplier->addColumn('notes', Types::TEXT, ['notnull' => false]);
        $supplier->addColumn('created', Types::DATETIME_MUTABLE);
        $supplier->addColumn('updated', Types::DATETIME_MUTABLE);
        $supplier->setPrimaryKey(['id']);
        $supplier->addIndex(['company_id'], 'idx_supplier_company');
        $supplier->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_supplier_company');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(Supplier::TABLE_NAME)) {
            $schema->dropTable(Supplier::TABLE_NAME);
        }
    }
}
