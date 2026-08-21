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
use function array_map;
use function sprintf;
use function strtolower;
use function strtoupper;

/**
 * Let an account be deleted again.
 *
 * user_settings.user_id was created (Version30000_1) with a plain foreign key,
 * which in MySQL means ON DELETE RESTRICT. Nothing owns an inverse collection of
 * those rows, so the ORM never removes them along with the account - the
 * database is the only thing that can, and it was told not to.
 *
 * That stayed harmless only for as long as the table was nearly empty. It stopped
 * being empty the day sign-in started recording which business you were last in
 * (UserSettingType::LastCompany, written by SelectCompany on every switch), which
 * gives every account that has ever logged in a row here. From then on, deleting
 * an account - which is what the membership console does when it removes a
 * business and the accounts that existed only for it - died on
 *
 *     Cannot delete or update a parent row: a foreign key constraint fails
 *
 * and the click came back as a 500.
 *
 * The constraint is looked up by column rather than by name because it was never
 * given one, so its name is whatever MySQL generated on the day the table was
 * built and differs between installs.
 */
final class Version30000_48 extends AbstractMigration
{
    private const TABLE = 'user_settings';

    private const COLUMN = 'user_id';

    public function getDescription(): string
    {
        return 'Delete a user\'s saved settings with the user, instead of blocking the delete.';
    }

    public function up(Schema $schema): void
    {
        $this->rebuildForeignKey('CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->rebuildForeignKey(null);
    }

    private function rebuildForeignKey(?string $onDelete): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (! $schemaManager->tablesExist([self::TABLE])) {
            return;
        }

        foreach ($schemaManager->listTableForeignKeys(self::TABLE) as $foreignKey) {
            $columns = array_map(strtolower(...), $foreignKey->getLocalColumns());

            if ($columns !== [self::COLUMN]) {
                continue;
            }

            $current = $foreignKey->onDelete();

            // Already what we want it to be - a second run of this migration, or
            // an install whose schema was built from the entity rather than from
            // Version30000_1 - so leave it alone.
            if (($current === null ? null : strtoupper($current)) === $onDelete) {
                return;
            }

            $this->addSql(
                sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', self::TABLE, $foreignKey->getName())
            );
        }

        $this->addSql(
            sprintf(
                'ALTER TABLE %s ADD CONSTRAINT fk_user_settings_user FOREIGN KEY (%s) REFERENCES users (id)%s',
                self::TABLE,
                self::COLUMN,
                $onDelete === null ? '' : ' ON DELETE ' . $onDelete,
            )
        );
    }
}
