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
use Symfony\Component\Intl\Countries;
use Symfony\Component\Uid\Ulid;
use function mb_ord;
use function mb_strtoupper;
use function str_replace;
use function strlen;
use function addcslashes;
use function array_slice;
use function count;
use function explode;
use function preg_replace;
use function preg_split;
use function str_contains;
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
     * @return array{listed: bool, whatsapp: string, country: string, city: string}
     */
    public function getForCompany(string $binaryCompanyId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT listed, whatsapp, country, city FROM ' . MarketplaceSetting::TABLE_NAME . ' WHERE company_id = ?',
            [$binaryCompanyId],
            [ParameterType::BINARY]
        );

        return [
            'listed' => (bool) ($row['listed'] ?? false),
            'whatsapp' => (string) ($row['whatsapp'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
        ];
    }

    /**
     * Save the active company's opt-in flag, WhatsApp number (digits only) and
     * location (ISO-2 country code + free-text city).
     */
    public function save(string $binaryCompanyId, bool $listed, ?string $whatsapp, ?string $country, ?string $city): void
    {
        $whatsapp = $this->normalizeNumber($whatsapp);
        $country = $this->normalizeCountry($country);
        $city = $city === null ? '' : trim($city);
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'INSERT INTO ' . MarketplaceSetting::TABLE_NAME . ' (id, company_id, listed, whatsapp, country, city, created, updated)
             VALUES (:id, :companyId, :listed, :whatsapp, :country, :city, :created, :updated)
             ON DUPLICATE KEY UPDATE listed = VALUES(listed), whatsapp = VALUES(whatsapp), country = VALUES(country), city = VALUES(city), updated = VALUES(updated)',
            [
                'id' => (new Ulid())->toBinary(),
                'companyId' => $binaryCompanyId,
                'listed' => $listed ? 1 : 0,
                'whatsapp' => $whatsapp === '' ? null : $whatsapp,
                'country' => $country === '' ? null : $country,
                'city' => $city === '' ? null : $city,
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
     * @return list<array{vendorId: string, business: string, model: string, qty: int, whatsapp: string, chatUrl: string, city: string, country: string, flag: string, location: string, logo: string}>
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
            // Match with spaces and hyphens removed on BOTH sides so the buyer does
            // not have to type the model the way each business happens to have it in
            // Tally - e.g. "1MIV" finds "1M IV", "s26-ultra" finds "S26 Ultra". LIKE
            // is already case-insensitive under the utf8mb4 collation.
            $token = str_replace([' ', '-'], '', $token);

            if ($token === '') {
                continue;
            }

            $where .= " AND REPLACE(REPLACE(sm.name, ' ', ''), '-', '') LIKE :t" . $i;
            $params['t' . $i] = '%' . addcslashes($token, '\\%_') . '%';
            $types['t' . $i] = ParameterType::STRING;
            ++$i;
        }

        if ($where === '') {
            return [];
        }

        // The seller's display picture is their company logo, uploaded under
        // Settings -> Company (app_config key system/company/logo, stored as
        // "type|base64"). LEFT JOIN so sellers without a logo still show.
        $rows = $this->connection->executeQuery(
            "SELECT HEX(c.id) AS vendorId, c.name AS business, sm.name AS model, sm.quantity AS qty,
                    ms.whatsapp AS whatsapp, ms.country AS country, ms.city AS city, cfg.setting_value AS logo
             FROM stock_model sm
             INNER JOIN companies c ON c.id = sm.company_id
             INNER JOIN " . MarketplaceSetting::TABLE_NAME . " ms ON ms.company_id = sm.company_id AND ms.listed = 1
             LEFT JOIN app_config cfg ON cfg.company_id = sm.company_id AND cfg.setting_key = 'system/company/logo'
             WHERE sm.quantity > 0" . $where . '
             ORDER BY sm.name ASC, c.name ASC
             LIMIT ' . self::MAX_RESULTS,
            $params,
            $types
        )->fetchAllAssociative();

        $results = [];

        foreach ($rows as $row) {
            $whatsapp = (string) ($row['whatsapp'] ?? '');
            $model = (string) ($row['model'] ?? '');
            $countryCode = (string) ($row['country'] ?? '');
            $city = (string) ($row['city'] ?? '');

            $results[] = [
                'vendorId' => (string) ($row['vendorId'] ?? ''),
                'business' => (string) ($row['business'] ?? ''),
                'model' => $model,
                'qty' => (int) ($row['qty'] ?? 0),
                'whatsapp' => $whatsapp,
                'chatUrl' => $whatsapp === '' ? '' : $this->chatUrl($whatsapp, $model),
                'city' => $city,
                'country' => $this->countryName($countryCode),
                'flag' => $this->flag($countryCode),
                // Human location line, e.g. "Dubai, United Arab Emirates".
                'location' => $this->location($city, $countryCode),
                // Ready-to-use data URI for the seller's logo, or '' if none.
                'logo' => $this->logoDataUri((string) ($row['logo'] ?? '')),
            ];
        }

        return $results;
    }

    /**
     * Search, then collapse to ONE listing per vendor: each vendor appears once
     * with the list of matching models (and quantities) they hold. The Chat link
     * uses what the buyer searched for, since a vendor may match several models.
     *
     * @return list<array{vendorId: string, business: string, city: string, country: string, flag: string, location: string, logo: string, whatsapp: string, chatUrl: string, items: list<array{model: string, qty: int}>}>
     */
    public function searchGrouped(string $query): array
    {
        $groups = [];

        foreach ($this->search($query) as $row) {
            $key = $row['vendorId'];

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'vendorId' => $row['vendorId'],
                    'business' => $row['business'],
                    'city' => $row['city'],
                    'country' => $row['country'],
                    'flag' => $row['flag'],
                    'location' => $row['location'],
                    'logo' => $row['logo'],
                    'whatsapp' => $row['whatsapp'],
                    'chatUrl' => $row['whatsapp'] === '' ? '' : $this->chatUrl($row['whatsapp'], trim($query)),
                    'items' => [],
                ];
            }

            $groups[$key]['items'][] = ['model' => $row['model'], 'qty' => $row['qty']];
        }

        return array_values($groups);
    }

    /**
     * @return list<array{code: string, name: string}> ISO-2 code + English name.
     */
    public function countryChoices(): array
    {
        $choices = [];

        foreach (Countries::getNames('en') as $code => $name) {
            $choices[] = ['code' => $code, 'name' => $name];
        }

        return $choices;
    }

    public function countryName(string $code): string
    {
        $code = mb_strtoupper(trim($code));

        return $code !== '' && Countries::exists($code) ? Countries::getName($code, 'en') : '';
    }

    /**
     * Flag emoji from an ISO-2 code, built from the two regional-indicator letters.
     */
    public function flag(string $code): string
    {
        $code = mb_strtoupper(trim($code));

        if (strlen($code) !== 2 || ! Countries::exists($code)) {
            return '';
        }

        return mb_chr(0x1F1E6 + (mb_ord($code[0]) - 65)) . mb_chr(0x1F1E6 + (mb_ord($code[1]) - 65));
    }

    private function location(string $city, string $code): string
    {
        $country = $this->countryName($code);
        $city = trim($city);

        if ($city !== '' && $country !== '') {
            return $city . ', ' . $country;
        }

        return $city !== '' ? $city : $country;
    }

    private function normalizeCountry(?string $code): string
    {
        $code = mb_strtoupper(trim((string) $code));

        return Countries::exists($code) ? $code : '';
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

    /**
     * Turn a stored logo value ("type|base64") into an <img>-ready data URI, or
     * '' when the seller has not uploaded a logo. Mirrors how the app renders
     * its own logo (GlobalExtension::displayAppLogo).
     */
    private function logoDataUri(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '' || ! str_contains($raw, '|')) {
            return '';
        }

        [$type, $data] = explode('|', $raw, 2);
        $type = trim($type);
        $data = trim($data);

        if ($type === '' || $data === '') {
            return '';
        }

        return 'data:image/' . $type . ';base64,' . $data;
    }
}
