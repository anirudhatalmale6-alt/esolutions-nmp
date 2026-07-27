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

namespace SolidInvoice\CoreBundle\Catalog;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SolidInvoice\CoreBundle\Entity\ModelCatalogEntry;
use SolidInvoice\CoreBundle\Entity\SharedModelCatalogEntry;
use Symfony\Component\Uid\Ulid;
use Throwable;
use function array_chunk;
use function array_values;
use function count;
use function implode;
use function is_array;
use function json_decode;
use function mb_strlen;
use function mb_substr;
use function preg_split;
use function trim;

/**
 * Reads and writes each company's editable phone-model list (model_catalog),
 * which feeds the line-item model suggestion box. A company's list is seeded once
 * from the built-in manufacturer catalogue (phone_models.json) and is then fully
 * owned by the company - editable from the "Manage model list" page.
 */
final class ModelCatalogManager
{
    private const CATALOG_FILE = __DIR__ . '/../Resources/data/phone_models.json';

    private const MAX_ENTRIES = 8000;

    /** @var list<string>|null */
    private ?array $defaultsCache = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The built-in manufacturer catalogue every company starts from.
     *
     * @return list<string>
     */
    public function defaults(): array
    {
        if ($this->defaultsCache !== null) {
            return $this->defaultsCache;
        }

        $json = @file_get_contents(self::CATALOG_FILE);
        $data = $json === false ? [] : json_decode($json, true);

        return $this->defaultsCache = $this->normalize(is_array($data) ? $data : []);
    }

    /**
     * Give a brand-new company the built-in list the first time it is needed.
     */
    public function ensureSeeded(string $binaryCompanyId): void
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . ModelCatalogEntry::TABLE_NAME . ' WHERE company_id = ?',
            [$binaryCompanyId],
            [ParameterType::BINARY]
        );

        if ($count > 0) {
            return;
        }

        $this->insertMany($binaryCompanyId, $this->defaults());
    }

    /**
     * The company's current model list, alphabetically.
     *
     * @return list<string>
     */
    public function names(string $binaryCompanyId): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn(
            'SELECT name FROM ' . ModelCatalogEntry::TABLE_NAME . ' WHERE company_id = ? ORDER BY name ASC',
            [$binaryCompanyId],
            [ParameterType::BINARY]
        );

        return $names;
    }

    /**
     * Replace the company's whole list with $names (already the "copy-paste and
     * update" contents). Returns the number of models saved.
     */
    public function replace(string $binaryCompanyId, array $names): int
    {
        $names = $this->normalize($names);

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                'DELETE FROM ' . ModelCatalogEntry::TABLE_NAME . ' WHERE company_id = ?',
                [$binaryCompanyId],
                [ParameterType::BINARY]
            );
            $this->insertMany($binaryCompanyId, $names);
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return count($names);
    }

    // ---------------------------------------------------------------------
    // Portal-wide shared list (model_catalog_shared). A single master list every
    // vendor reads from; only the Super User edits it. The per-company methods
    // above are kept for old data but are no longer used to feed suggestions.
    // ---------------------------------------------------------------------

    /**
     * The portal-wide shared model list, alphabetically.
     *
     * @return list<string>
     */
    public function sharedNames(): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn(
            'SELECT name FROM ' . SharedModelCatalogEntry::TABLE_NAME . ' ORDER BY name ASC'
        );

        return $names;
    }

    /**
     * Fill the shared list the first time it is needed. To preserve any list a
     * vendor has already curated, we seed it from the union of every existing
     * per-company list; only if there is nothing at all do we fall back to the
     * built-in manufacturer catalogue.
     */
    public function ensureSharedSeeded(): void
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . SharedModelCatalogEntry::TABLE_NAME
        );

        if ($count > 0) {
            return;
        }

        /** @var list<string> $existing */
        $existing = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT name FROM ' . ModelCatalogEntry::TABLE_NAME . ' ORDER BY name ASC'
        );

        $seed = $existing !== [] ? $existing : $this->defaults();

        $this->insertManyShared($this->normalize($seed));
    }

    /**
     * Replace the whole shared list with $names. Returns the number saved.
     *
     * @param array<mixed> $names
     */
    public function replaceShared(array $names): int
    {
        $names = $this->normalize($names);

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('DELETE FROM ' . SharedModelCatalogEntry::TABLE_NAME);
            $this->insertManyShared($names);
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return count($names);
    }

    /**
     * @param list<string> $names
     */
    private function insertManyShared(array $names): void
    {
        if ($names === []) {
            return;
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        foreach (array_chunk($names, 100) as $chunk) {
            $rows = [];
            $params = [];
            $types = [];

            foreach ($chunk as $name) {
                $rows[] = '(?, ?, ?, ?)';
                $params[] = (new Ulid())->toBinary();
                $params[] = $name;
                $params[] = $now;
                $params[] = $now;
                $types[] = ParameterType::BINARY;
                $types[] = ParameterType::STRING;
                $types[] = ParameterType::STRING;
                $types[] = ParameterType::STRING;
            }

            $this->connection->executeStatement(
                'INSERT IGNORE INTO ' . SharedModelCatalogEntry::TABLE_NAME
                . ' (id, name, created, updated) VALUES ' . implode(', ', $rows),
                $params,
                $types
            );
        }
    }

    /**
     * Split pasted text (one model per line, or comma-separated) into a clean,
     * de-duplicated list.
     *
     * @return list<string>
     */
    public function parse(string $text): array
    {
        $parts = preg_split('/[\r\n,]+/', $text) ?: [];

        return $this->normalize($parts);
    }

    /**
     * Trim, drop blanks, cap length, de-duplicate case-insensitively (keeping the
     * first spelling) and cap the total.
     *
     * @param array<mixed> $names
     * @return list<string>
     */
    private function normalize(array $names): array
    {
        $seen = [];

        foreach ($names as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            if (mb_strlen($name) > 255) {
                $name = trim(mb_substr($name, 0, 255));
            }

            $key = mb_strtolower($name);

            if (! isset($seen[$key])) {
                $seen[$key] = $name;
            }

            if (count($seen) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return array_values($seen);
    }

    /**
     * @param list<string> $names
     */
    private function insertMany(string $binaryCompanyId, array $names): void
    {
        if ($names === []) {
            return;
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        foreach (array_chunk($names, 100) as $chunk) {
            $rows = [];
            $params = [];
            $types = [];

            foreach ($chunk as $name) {
                $rows[] = '(?, ?, ?, ?, ?)';
                $params[] = (new Ulid())->toBinary();
                $params[] = $binaryCompanyId;
                $params[] = $name;
                $params[] = $now;
                $params[] = $now;
                $types[] = ParameterType::BINARY;
                $types[] = ParameterType::BINARY;
                $types[] = ParameterType::STRING;
                $types[] = ParameterType::STRING;
                $types[] = ParameterType::STRING;
            }

            // INSERT IGNORE so the unique (company, name) constraint silently drops
            // any duplicate rather than erroring (also makes seeding race-safe).
            $this->connection->executeStatement(
                'INSERT IGNORE INTO ' . ModelCatalogEntry::TABLE_NAME
                . ' (id, company_id, name, created, updated) VALUES ' . implode(', ', $rows),
                $params,
                $types
            );
        }
    }
}
