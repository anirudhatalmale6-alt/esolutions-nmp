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

namespace SolidInvoice\CoreBundle\Marketplace;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SolidInvoice\CoreBundle\Entity\MarketplaceSetting;
use Symfony\Component\Uid\Ulid;
use function addcslashes;
use function array_slice;
use function count;
use function preg_replace;
use function preg_split;
use function trim;

/**
 * Backs the public Marketplace: a buyer searches a phone model and we look across
 * every business that has opted in, reading their Tally stock (stock_model). Each
 * business owns a MarketplaceSetting row deciding whether it is listed and which
 * WhatsApp number buyers are handed.
 *
 * Search runs through raw DBAL on purpose - it must cross every company, so it
 * must NOT be scoped to the active company the way normal reads are.
 */
final class MarketplaceManager
{
    private const MAX_TOKENS = 6;

    private const MAX_RESULTS = 200;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The active company's own marketplace settings.
     *
     * @return array{listed: bool, whatsapp: string}
     */
    public function getForCompany(string $binaryCompanyId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT listed, whatsapp FROM ' . MarketplaceSetting::TABLE_NAME . ' WHERE company_id = ?',
            [$binaryCompanyId],
            [ParameterType::BINARY]
        );

        return [
            'listed' => (bool) ($row['listed'] ?? false),
            'whatsapp' => (string) ($row['whatsapp'] ?? ''),
        ];
    }

    /**
     * Save the active company's opt-in flag and WhatsApp number (digits only).
     */
    public function save(string $binaryCompanyId, bool $listed, ?string $whatsapp): void
    {
        $whatsapp = $this->normalizeNumber($whatsapp);
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'INSERT INTO ' . MarketplaceSetting::TABLE_NAME . ' (id, company_id, listed, whatsapp, created, updated)
             VALUES (:id, :companyId, :listed, :whatsapp, :created, :updated)
             ON DUPLICATE KEY UPDATE listed = VALUES(listed), whatsapp = VALUES(whatsapp), updated = VALUES(updated)',
            [
                'id' => (new Ulid())->toBinary(),
                'companyId' => $binaryCompanyId,
                'listed' => $listed ? 1 : 0,
                'whatsapp' => $whatsapp === '' ? null : $whatsapp,
                'created' => $now,
                'updated' => $now,
            ],
            [
                'id' => ParameterType::BINARY,
                'companyId' => ParameterType::BINARY,
                'listed' => ParameterType::INTEGER,
            ]
        );
    }

    /**
     * Every listed business that has the searched model in stock (quantity > 0).
     *
     * @return list<array{business: string, model: string, qty: int, whatsapp: string, chatUrl: string}>
     */
    public function search(string $query): array
    {
        $tokens = array_slice(array_filter(
            preg_split('/\s+/', trim($query)) ?: [],
            static fn (string $t): bool => $t !== ''
        ), 0, self::MAX_TOKENS);

        if ($tokens === []) {
            return [];
        }

        $where = '';
        $params = [];
        $types = [];
        $i = 0;

        foreach ($tokens as $token) {
            // Match with spaces removed on BOTH sides so the buyer does not have to
            // type the model exactly the way it sits in Tally - e.g. "1MIV" finds
            // "1M IV", "s26ultra" finds "S26 Ultra". LIKE is already
            // case-insensitive under the utf8mb4 collation.
            $token = str_replace(' ', '', $token);
            $where .= " AND REPLACE(sm.name, ' ', '') LIKE :t" . $i;
            $params['t' . $i] = '%' . addcslashes($token, '\\%_') . '%';
            $types['t' . $i] = ParameterType::STRING;
            ++$i;
        }

        $rows = $this->connection->executeQuery(
            'SELECT c.name AS business, sm.name AS model, sm.quantity AS qty, ms.whatsapp AS whatsapp
             FROM stock_model sm
             INNER JOIN companies c ON c.id = sm.company_id
             INNER JOIN ' . MarketplaceSetting::TABLE_NAME . ' ms ON ms.company_id = sm.company_id AND ms.listed = 1
             WHERE sm.quantity > 0' . $where . '
             ORDER BY sm.name ASC, c.name ASC
             LIMIT ' . self::MAX_RESULTS,
            $params,
            $types
        )->fetchAllAssociative();

        $results = [];

        foreach ($rows as $row) {
            $whatsapp = (string) ($row['whatsapp'] ?? '');
            $model = (string) ($row['model'] ?? '');

            $results[] = [
                'business' => (string) ($row['business'] ?? ''),
                'model' => $model,
                'qty' => (int) ($row['qty'] ?? 0),
                'whatsapp' => $whatsapp,
                'chatUrl' => $whatsapp === '' ? '' : $this->chatUrl($whatsapp, $model),
            ];
        }

        return $results;
    }

    /**
     * The WhatsApp deep link handed to a buyer, pre-filled with the 2-line message
     * mentioning the model they searched for.
     */
    public function chatUrl(string $whatsapp, string $model): string
    {
        $text = "Hi, I got your details from eSolutions.\n"
            . 'I am looking for ' . $model . ' - could you please share price and availability?';

        return 'https://wa.me/' . $whatsapp . '?text=' . rawurlencode($text);
    }

    /**
     * Collapse detailed rows into an anonymous, seller-hidden view for guests:
     * one row per model with total quantity and how many sellers have it.
     *
     * @param list<array{business: string, model: string, qty: int, whatsapp: string}> $rows
     * @return list<array{model: string, qty: int, sellers: int}>
     */
    public function aggregate(array $rows): array
    {
        $byModel = [];

        foreach ($rows as $row) {
            $model = $row['model'];

            if (! isset($byModel[$model])) {
                $byModel[$model] = ['model' => $model, 'qty' => 0, 'sellers' => 0];
            }

            $byModel[$model]['qty'] += $row['qty'];
            ++$byModel[$model]['sellers'];
        }

        return array_values($byModel);
    }

    private function normalizeNumber(?string $number): string
    {
        if ($number === null) {
            return '';
        }

        // wa.me needs digits only (country code included, no +, spaces or dashes).
        return (string) preg_replace('/\D+/', '', $number);
    }
}
