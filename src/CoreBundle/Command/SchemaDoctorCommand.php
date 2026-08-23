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

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use function count;
use function sprintf;

/**
 * Does the database actually have the columns the code is about to ask for?
 *
 * An update copies the new code first and applies the database change a few
 * seconds later. If that database step does not finish - or is recorded as done
 * without having done anything, which has happened here once already - the site
 * is left running code that asks for a column the database does not have. That
 * is not a missing feature, it is a 500 on every page that loads the affected
 * record, and for the users table that means NOBODY CAN LOG IN. The update still
 * printed UPDATE DONE.
 *
 * So the assumption is checked instead of made, on every update, right after the
 * migrations run.
 *
 * Deliberately narrow. It only ever ADDS A COLUMN THAT IS ALLOWED TO BE EMPTY -
 * the one change that cannot lose anything and cannot fail on a table that
 * already has rows in it. Anything else the schema disagrees about is REPORTED
 * and left alone: dropping a column, narrowing a type or adding a NOT NULL
 * column are decisions for a migration written by hand, not for a repair tool
 * running unattended at the end of a deploy.
 */
#[AsCommand(
    name: 'app:schema-doctor',
    description: 'Reports columns the code expects but the database does not have, and can add the ones that are safe to add.',
)]
final class SchemaDoctorCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fix', null, InputOption::VALUE_NONE, 'Add the missing columns that are safe to add.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        try {
            $schemaManager = $connection->createSchemaManager();

            $expected = new SchemaTool($this->entityManager)
                ->getSchemaFromMetadata($this->entityManager->getMetadataFactory()->getAllMetadata());

            $diff = $schemaManager->createComparator()
                ->compareSchemas($schemaManager->introspectSchema(), $expected);
        } catch (Throwable $e) {
            // Never the reason a deploy fails. A schema that cannot even be read
            // is a real problem, but this command is the messenger.
            $io->warning(sprintf('The database structure could not be compared: %s', $e->getMessage()));

            return Command::SUCCESS;
        }

        /** @var list<array{table: string, column: Column, from: Table}> $addable */
        $addable = [];
        /** @var list<string> $needsAMigration */
        $needsAMigration = [];

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $oldTable = $tableDiff->getOldTable();

            if (! $oldTable instanceof Table) {
                continue;
            }

            $table = $oldTable->getName();

            foreach ($tableDiff->getAddedColumns() as $column) {
                if ($column->getNotnull()) {
                    // A NOT NULL column cannot be added to a table that already
                    // has rows without deciding what those rows should say.
                    $needsAMigration[] = sprintf('%s.%s is missing and cannot be empty - it needs a migration that says what to put in it', $table, $column->getName());

                    continue;
                }

                $addable[] = ['table' => $table, 'column' => $column, 'from' => $oldTable];
            }

            foreach ($tableDiff->getDroppedColumns() as $column) {
                $needsAMigration[] = sprintf('%s.%s is in the database but not in the code - left alone', $table, $column->getName());
            }

            foreach ($tableDiff->getModifiedColumns() as $columnDiff) {
                $needsAMigration[] = sprintf('%s.%s is a different type than the code expects - left alone', $table, $columnDiff->getOldColumn()?->getName() ?? $columnDiff->getNewColumn()->getName());
            }
        }

        if ($addable === []) {
            $io->success('Every column the code needs is in the database.');
        } else {
            $io->writeln(sprintf('%d column(s) the code needs are missing from the database:', count($addable)));

            foreach ($addable as $missing) {
                $io->writeln(sprintf('  %s.%s', $missing['table'], $missing['column']->getName()));
            }
        }

        foreach ($needsAMigration as $note) {
            $io->writeln('  note: ' . $note);
        }

        if ($addable === []) {
            return Command::SUCCESS;
        }

        if (! $input->getOption('fix')) {
            $io->warning('Run this again with --fix to add them. Until then the pages that use them will fail.');

            return Command::SUCCESS;
        }

        $added = 0;

        foreach ($this->byTable($addable) as $table => $columns) {
            /*
             * The platform writes the ALTER, not this class.
             *
             * Hand-assembling "ALTER TABLE x ADD y <declaration>" loses the
             * `(DC2Type:...)` comment that MySQL needs to tell a DATETIME from an
             * immutable one - so the column would be added, the page would load,
             * and Doctrine would then report the schema as still wrong for ever.
             * Going through getAlterTableSQL produces exactly what a migration
             * would have written.
             */
            $diff = new TableDiff($table, $columns['columns'], [], [], [], [], [], $columns['from']);

            foreach ($platform->getAlterTableSQL($diff) as $sql) {
                try {
                    $connection->executeStatement($sql);
                    ++$added;
                    $io->writeln('  ' . $sql);
                } catch (Throwable $e) {
                    $io->warning(sprintf('Could not run "%s": %s', $sql, $e->getMessage()));
                }
            }

            foreach ($columns['columns'] as $column) {
                $io->writeln(sprintf('  added %s.%s', $table, $column->getName()));
            }
        }

        $io->success(sprintf('%d statement(s) run. Nothing was removed or rewritten.', $added));

        return Command::SUCCESS;
    }

    /**
     * @param list<array{table: string, column: Column, from: Table}> $addable
     *
     * @return array<string, array{columns: list<Column>, from: Table}>
     */
    private function byTable(array $addable): array
    {
        $grouped = [];

        foreach ($addable as $missing) {
            $grouped[$missing['table']]['columns'][] = $missing['column'];
            $grouped[$missing['table']]['from'] = $missing['from'];
        }

        return $grouped;
    }
}
