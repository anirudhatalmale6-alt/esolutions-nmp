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
use SolidInvoice\CoreBundle\Entity\MarketplaceSetting;
use Symfony\Component\Uid\Ulid;

/**
 * Gives every business on the portal its own public stock page, instead of the
 * single hard-wired one.
 *
 * The old /nmp-inventory page read the stock table with no company of its own,
 * so on a portal with more than one business it showed everybody's stock at
 * once under whichever name happened to come first. Each business now owns a
 * switch and an address of its own (/inventory/{slug}), and the page only ever
 * shows that business's stock.
 *
 * The seed below keeps the existing shared link alive: the business the page
 * was really showing - the one holding the most stock - is switched on and
 * given the slug "nmp", which is what /nmp-inventory now resolves to. Every
 * other business starts switched OFF, so this migration cannot publish anyone's
 * stock who had not already published it.
 */
final class Version30000_38 extends AbstractMigration
{
    private const LEGACY_SLUG = 'nmp';

    public function getDescription(): string
    {
        return 'Per-business public stock page (own on/off switch and own link).';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable(MarketplaceSetting::TABLE_NAME)) {
            return;
        }

        $table = $schema->getTable(MarketplaceSetting::TABLE_NAME);

        if (! $table->hasColumn('share_stock')) {
            $table->addColumn('share_stock', 'boolean', ['notnull' => true, 'default' => 0]);
        }

        if (! $table->hasColumn('share_slug')) {
            $table->addColumn('share_slug', 'string', ['length' => 60, 'notnull' => false]);
        }

        // Unique so two businesses can never claim the same public address.
        if (! $table->hasIndex('uniq_marketplace_setting_share_slug')) {
            $table->addUniqueIndex(['share_slug'], 'uniq_marketplace_setting_share_slug');
        }
    }

    /**
     * Run after the columns exist: switch the existing public page back on for
     * the business it was actually showing, so the link already in customers'
     * hands keeps working.
     */
    public function postUp(Schema $schema): void
    {
        if (! $schema->hasTable(MarketplaceSetting::TABLE_NAME)) {
            return;
        }

        // Already claimed (this migration re-run, or the admin set it by hand).
        $taken = $this->connection->fetchOne(
            'SELECT 1 FROM ' . MarketplaceSetting::TABLE_NAME . ' WHERE share_slug = ?',
            [self::LEGACY_SLUG]
        );

        if ($taken !== false) {
            return;
        }

        $companyId = $this->legacyCompanyId();

        if ($companyId === null) {
            return;
        }

        $now = $this->connection->fetchOne('SELECT NOW()');

        $this->connection->executeStatement(
            'INSERT INTO ' . MarketplaceSetting::TABLE_NAME . ' (id, company_id, listed, share_stock, share_slug, created, updated)
             VALUES (:id, :companyId, 0, 1, :slug, :now, :now2)
             ON DUPLICATE KEY UPDATE share_stock = 1, share_slug = VALUES(share_slug), updated = VALUES(updated)',
            [
                'id' => (new Ulid())->toBinary(),
                'companyId' => $companyId,
                'slug' => self::LEGACY_SLUG,
                'now' => $now,
                'now2' => $now,
            ],
            [
                'id' => ParameterType::BINARY,
                'companyId' => ParameterType::BINARY,
            ]
        );
    }

    /**
     * The business the old shared page was really showing: it listed stock in
     * name order with no company filter, so in practice that is whoever holds
     * the most stock. Falls back to the oldest business on a portal with no
     * stock imported yet.
     */
    private function legacyCompanyId(): ?string
    {
        $companyId = $this->connection->fetchOne(
            'SELECT company_id FROM stock_model GROUP BY company_id ORDER BY COUNT(*) DESC LIMIT 1'
        );

        if ($companyId === false || $companyId === null) {
            $companyId = $this->connection->fetchOne('SELECT id FROM companies ORDER BY id ASC LIMIT 1');
        }

        return $companyId === false || $companyId === null ? null : (string) $companyId;
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable(MarketplaceSetting::TABLE_NAME)) {
            return;
        }

        $table = $schema->getTable(MarketplaceSetting::TABLE_NAME);

        if ($table->hasIndex('uniq_marketplace_setting_share_slug')) {
            $table->dropIndex('uniq_marketplace_setting_share_slug');
        }

        foreach (['share_stock', 'share_slug'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }
}
