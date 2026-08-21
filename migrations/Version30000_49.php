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
 * The other three keys that refuse to let an account go.
 *
 * Version30000_48 fixed user_settings. It was not alone: Version20200 and
 * Version20300 built three more foreign keys pointing at users with a plain
 * addForeignKeyConstraint() and no options, which in MySQL is ON DELETE
 * RESTRICT, and no entity owns an inverse collection of any of them:
 *
 *   user_invitations.invited_by_id       - who sent an invitation
 *   notification_transport_setting.user_id
 *   notification_user_setting.user_id    - written whenever somebody saves
 *                                          their notification preferences
 *
 * Version30000_5 went through this same schema adding CASCADE, but only to the
 * company_id keys - every user_id key was left as it was. So deleting a
 * business still works and deleting the person does not, which is why the
 * console could remove a company and then die halfway through removing the
 * account that existed only for it, leaving the account behind holding its
 * e-mail address and WhatsApp number.
 *
 * CASCADE is right for all three. An invitation with no sender, and a
 * notification preference belonging to nobody, are not records worth keeping.
 * The join table under notification_user_setting already cascades from it
 * (Version20300), so that one cleans itself up the rest of the way.
 *
 * Looked up by column, not by name: none of these constraints was ever named,
 * so each install has whatever MySQL generated the day it was built.
 */
final class Version30000_49 extends AbstractMigration
{
    /**
     * table => [column, name to give the rebuilt constraint]
     */
    private const KEYS = [
        'user_invitations' => ['invited_by_id', 'fk_user_invitations_invited_by'],
        'notification_transport_setting' => ['user_id', 'fk_notification_transport_setting_user'],
        'notification_user_setting' => ['user_id', 'fk_notification_user_setting_user'],
    ];

    public function getDescription(): string
    {
        return 'Delete a user\'s invitations and notification preferences with the user, instead of blocking the delete.';
    }

    public function up(Schema $schema): void
    {
        $this->rebuildForeignKeys('CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->rebuildForeignKeys(null);
    }

    private function rebuildForeignKeys(?string $onDelete): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        foreach (self::KEYS as $table => [$column, $constraintName]) {
            if (! $schemaManager->tablesExist([$table])) {
                continue;
            }

            $alreadyCorrect = false;

            foreach ($schemaManager->listTableForeignKeys($table) as $foreignKey) {
                $columns = array_map(strtolower(...), $foreignKey->getLocalColumns());

                if ($columns !== [$column]) {
                    continue;
                }

                $current = $foreignKey->onDelete();

                // Already what we want - a second run, or an install whose schema
                // was generated from the entities rather than from the migrations.
                if (($current === null ? null : strtoupper($current)) === $onDelete) {
                    $alreadyCorrect = true;

                    break;
                }

                $this->addSql(
                    sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $foreignKey->getName())
                );
            }

            if ($alreadyCorrect) {
                continue;
            }

            $this->addSql(
                sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES users (id)%s',
                    $table,
                    $constraintName,
                    $column,
                    $onDelete === null ? '' : ' ON DELETE ' . $onDelete,
                )
            );
        }
    }
}
