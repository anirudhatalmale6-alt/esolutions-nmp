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

namespace SolidInvoice\CoreBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SolidInvoice\SettingsBundle\Config\ProviderInterface;
use SolidInvoice\SettingsBundle\DTO\Config;
use SolidInvoice\SettingsBundle\Entity\Setting;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Ulid;
use function array_diff;
use function array_keys;
use function count;
use function explode;
use function implode;
use function sprintf;

/**
 * Why is a settings tab missing?
 *
 * The Settings page shows one tab per group of rows that exist in app_config
 * FOR THE CURRENT COMPANY. A config provider only creates its rows when a
 * company is created, so a feature added afterwards needs a migration to
 * back-fill every business that already existed - and if that back-fill did not
 * happen, or happened for some businesses and not others, the tab is simply
 * absent with nothing anywhere to say why.
 *
 * That is not hypothetical: the WhatsApp tab was missing from a live site while
 * the update reported the database as fully up to date.
 *
 * Reads through the raw connection rather than the entity manager on purpose:
 * Setting is company-filtered, and on the command line there is no current
 * company, so the ORM would answer for nothing at all.
 */
#[AsCommand(
    name: 'app:settings-doctor',
    description: 'Reports which settings each business has, and can create any that are missing.',
)]
final class SettingsDoctorCommand extends Command
{
    /**
     * @param iterable<ProviderInterface> $configProviders
     */
    public function __construct(
        private readonly Connection $connection,
        #[AutowireIterator(ProviderInterface::class)]
        private readonly iterable $configProviders,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fix', null, InputOption::VALUE_NONE, 'Create the settings rows that are missing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $expected = $this->expectedSettings();

        $io->section('What this version of the code expects');

        /*
         * The providers are listed by name, and it is not decoration. If the
         * live site is running older code the WhatsApp provider is simply not
         * there, "expected" never contains a whatsapp key, and every business
         * is then reported as having nothing missing - a clean bill of health
         * for the exact fault being looked for. Seeing the provider listed is
         * what makes the rest of this report mean anything.
         */
        foreach ($this->configProviders as $provider) {
            $io->writeln('provider: ' . $provider::class);
        }

        $io->writeln(sprintf('%d settings, in these groups: %s', count($expected), implode(', ', $this->groups(array_keys($expected)))));

        if (! isset($expected['whatsapp/instance_id'])) {
            $io->warning('This site is running code from before the WhatsApp feature. Run the update (deploy.sh) first, then run this again.');

            return Command::SUCCESS;
        }

        $companies = $this->connection->fetchAllAssociative('SELECT id, name FROM companies ORDER BY name');

        if ($companies === []) {
            $io->warning('There are no businesses on this site at all.');

            return Command::SUCCESS;
        }

        $fix = (bool) $input->getOption('fix');
        $missingAnywhere = 0;
        $created = 0;

        foreach ($companies as $company) {
            $companyId = $company['id'];

            $present = $this->connection->fetchFirstColumn(
                'SELECT setting_key FROM ' . Setting::TABLE_NAME . ' WHERE company_id = ?',
                [$companyId],
                [ParameterType::BINARY],
            );

            $missing = array_diff(array_keys($expected), $present);

            $io->section((string) $company['name']);
            $io->writeln(sprintf('has %d settings, in these groups: %s', count($present), implode(', ', $this->groups($present))));

            if ($missing === []) {
                $io->writeln('nothing missing');

                continue;
            }

            $missingAnywhere += count($missing);
            $io->writeln(sprintf('MISSING %d: %s', count($missing), implode(', ', $missing)));

            if (! $fix) {
                continue;
            }

            foreach ($missing as $key) {
                $this->insert($companyId, $expected[$key]);
                ++$created;
            }

            $io->writeln(sprintf('created %d', count($missing)));
        }

        $io->section('Database updates');

        foreach ($this->migrationState() as $line) {
            $io->writeln($line);
        }

        $io->section('Summary');

        if ($missingAnywhere === 0) {
            $io->success('Every business has every setting. A tab that is still missing is not caused by missing settings rows.');

            return Command::SUCCESS;
        }

        if ($fix) {
            $io->success(sprintf('Created %d missing settings. Reload the Settings page - the tab should be there now.', $created));

            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d settings are missing. Run this again with --fix to create them.', $missingAnywhere));

        return Command::SUCCESS;
    }

    /**
     * Every setting this version of the code defines, keyed by its key.
     *
     * @return array<string, Config>
     */
    private function expectedSettings(): array
    {
        $expected = [];

        foreach ($this->configProviders as $provider) {
            // The providers that build a value out of the new company's details
            // (its name, its currency) are given empty ones here: this only ever
            // asks WHICH settings exist, never what a missing one should say,
            // and a row seeded with an empty default is corrected the moment the
            // owner saves that tab.
            foreach ($provider->provide(['currency' => '', 'company_name' => '']) as $config) {
                if ($config instanceof Config) {
                    $expected[$config->key] = $config;
                }
            }
        }

        return $expected;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function groups(array $keys): array
    {
        $groups = [];

        foreach ($keys as $key) {
            $groups[explode('/', $key)[0]] = true;
        }

        return array_keys($groups);
    }

    private function insert(mixed $companyId, Config $config): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . Setting::TABLE_NAME
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

    /**
     * Which of the recent database updates this site has recorded.
     *
     * Named individually rather than dumped wholesale: the point is to see at a
     * glance whether the ones that back-fill settings were applied here.
     *
     * @return list<string>
     */
    private function migrationState(): array
    {
        if (! $this->connection->createSchemaManager()->tablesExist(['doctrine_migration_versions'])) {
            return ['no record of any database updates on this site'];
        }

        $lines = [];

        foreach (['Version30000_47', 'Version30000_48', 'Version30000_49'] as $version) {
            $executedAt = $this->connection->fetchOne(
                'SELECT executed_at FROM doctrine_migration_versions WHERE version LIKE ?',
                ['%' . $version],
            );

            $lines[] = sprintf(
                '%s - %s',
                $version,
                $executedAt === false ? 'NOT applied' : 'applied ' . $executedAt,
            );
        }

        return $lines;
    }
}
