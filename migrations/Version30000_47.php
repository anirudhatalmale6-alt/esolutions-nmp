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

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use SolidInvoice\CoreBundle\Config\WhatsAppConfigProvider;
use Symfony\Component\Uid\Ulid;

/**
 * Seed the WhatsApp gateway settings for businesses that already exist.
 *
 * DefaultData::createAppConfig() only runs when a COMPANY IS CREATED, so a new
 * config provider gives its settings to future businesses and to nobody else.
 * Without this the WhatsApp tab is simply absent for every account already on
 * the portal - including the owner's, which is the one account that needs it.
 */
final class Version30000_47 extends AbstractMigration
{
    private const TABLE = 'app_config';

    private const COMPANIES = 'companies';

    public function getDescription(): string
    {
        return 'Add the WhatsApp gateway settings to companies that already exist.';
    }

    public function up(Schema $schema): void
    {
        $this->seed(false);
    }

    public function down(Schema $schema): void
    {
        $this->seed(true);
    }

    private function seed(bool $remove): void
    {
        if (! $this->connection->createSchemaManager()->tablesExist([self::TABLE, self::COMPANIES])) {
            return;
        }

        $configs = (new WhatsAppConfigProvider())->provide([]);

        if ($remove) {
            foreach ($configs as $config) {
                $this->addSql(
                    'DELETE FROM ' . self::TABLE . ' WHERE setting_key = ?',
                    [$config->key],
                    [ParameterType::STRING],
                );
            }

            return;
        }

        $companies = $this->connection->fetchFirstColumn('SELECT id FROM ' . self::COMPANIES);

        foreach ($companies as $companyId) {
            foreach ($configs as $config) {
                // Re-running the migration, or a company created after the
                // provider shipped, must not end up with the key twice - the
                // settings form would then render two inputs for one value.
                $exists = $this->connection->fetchOne(
                    'SELECT 1 FROM ' . self::TABLE . ' WHERE company_id = ? AND setting_key = ?',
                    [$companyId, $config->key],
                    [ParameterType::BINARY, ParameterType::STRING],
                );

                if ($exists !== false) {
                    continue;
                }

                $this->addSql(
                    'INSERT INTO ' . self::TABLE
                    . ' (id, company_id, setting_key, setting_value, description, field_type, form_options, default_value)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        (new Ulid())->toBinary(),
                        $companyId,
                        $config->key,
                        (string) $config->value,
                        $config->description,
                        $config->formType,
                        '[]',
                        (string) $config->value,
                    ],
                    [
                        ParameterType::BINARY,
                        ParameterType::BINARY,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::STRING,
                    ],
                );
            }
        }
    }
}
