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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SolidInvoice\CoreBundle\Entity\MarketplaceSetting;
use SolidInvoice\CoreBundle\Form\Type\ImageUploadType;
use SolidInvoice\SettingsBundle\Entity\Setting;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Uid\Ulid;
use Throwable;
use function bin2hex;
use function is_string;
use function mb_ord;
use function mb_strtoupper;
use function rtrim;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
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

    /** Bounds of a public stock page address; must match the route requirement. */
    public const int MIN_SLUG_LENGTH = 3;

    public const int MAX_SLUG_LENGTH = 60;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The active company's own marketplace settings. "logo" is a ready-to-use
     * data URI for the seller's own listing logo (the one they upload here,
     * NOT the invoice/company logo), or '' when none has been uploaded.
     *
     * @return array{listed: bool, whatsapp: string, country: string, city: string, logo: string, shareStock: bool, shareSlug: string}
     */
    public function getForCompany(string $binaryCompanyId): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT listed, whatsapp, country, city, share_stock, share_slug FROM ' . MarketplaceSetting::TABLE_NAME . ' WHERE company_id = ?',
                [$binaryCompanyId],
                [ParameterType::BINARY]
            );
        } catch (Throwable) {
            // The share columns arrive with a migration, and the code is deployed
            // before it runs. In that window the stock page must still open - just
            // without a share link - rather than break on a column that is coming.
            $row = false;
        }

        $logo = (string) ($this->connection->fetchOne(
            'SELECT setting_value FROM ' . Setting::TABLE_NAME . " WHERE company_id = ? AND setting_key = 'marketplace/logo'",
            [$binaryCompanyId],
            [ParameterType::BINARY]
        ) ?: '');

        return [
            'listed' => (bool) ($row['listed'] ?? false),
            'whatsapp' => (string) ($row['whatsapp'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'logo' => $this->logoDataUri($logo),
            'shareStock' => (bool) ($row['share_stock'] ?? false),
            'shareSlug' => (string) ($row['share_slug'] ?? ''),
        ];
    }

    /**
     * Save (or clear) the company's own Marketplace listing logo, kept separate
     * from the invoice/company logo so a seller can use a colour image here
     * without affecting their invoices. Stored in app_config under the key
     * "marketplace/logo" as "type|base64", exactly like the company logo.
     *
     *   $logo === null -> leave the current logo untouched
     *   $logo === ''   -> remove the logo
     *   otherwise      -> set/replace it ("type|base64")
     */
    public function saveLogo(string $binaryCompanyId, ?string $logo): void
    {
        if ($logo === null) {
            return;
        }

        if ($logo === '') {
            $this->connection->executeStatement(
                'DELETE FROM ' . Setting::TABLE_NAME . " WHERE company_id = ? AND setting_key = 'marketplace/logo'",
                [$binaryCompanyId],
                [ParameterType::BINARY]
            );

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO ' . Setting::TABLE_NAME . " (id, company_id, setting_key, setting_value, field_type)
             VALUES (:id, :companyId, 'marketplace/logo', :value, :type)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [
                'id' => (new Ulid())->toBinary(),
                'companyId' => $binaryCompanyId,
                'value' => $logo,
                'type' => ImageUploadType::class,
            ],
            [
                'id' => ParameterType::BINARY,
                'companyId' => ParameterType::BINARY,
            ]
        );
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
     * Turn the company's own public stock page on or off, and set the address it
     * lives at. Written separately from {@see save()} because the two are
     * independent channels - the Marketplace listing and a business's own link -
     * and saving one must not quietly change the other.
     *
     * Returns false, changing nothing, when the address belongs to another
     * business. That check lives HERE rather than only in the form: share_slug is
     * unique, so an INSERT ... ON DUPLICATE KEY carrying someone else's address
     * would match on THAT key and quietly rewrite their row - taking their stock
     * page down. A caller must not be able to reach that by accident.
     */
    public function saveStockShare(string $binaryCompanyId, bool $shareStock, string $slug): bool
    {
        $slug = $this->normalizeSlug($slug);

        if ($slug !== '' && ! $this->slugAvailable($slug, $binaryCompanyId)) {
            return false;
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        try {
            $this->connection->executeStatement(
                'INSERT INTO ' . MarketplaceSetting::TABLE_NAME . ' (id, company_id, listed, share_stock, share_slug, created, updated)
                 VALUES (:id, :companyId, 0, :shareStock, :slug, :created, :updated)
                 ON DUPLICATE KEY UPDATE share_stock = VALUES(share_stock), share_slug = VALUES(share_slug), updated = VALUES(updated)',
                [
                    'id' => (new Ulid())->toBinary(),
                    'companyId' => $binaryCompanyId,
                    'shareStock' => $shareStock ? 1 : 0,
                    'slug' => $slug === '' ? null : $slug,
                    'created' => $now,
                    'updated' => $now,
                ],
                [
                    'id' => ParameterType::BINARY,
                    'companyId' => ParameterType::BINARY,
                    'shareStock' => ParameterType::INTEGER,
                ]
            );
        } catch (Throwable) {
            // Same deploy window: the columns are not there yet, so there is
            // nothing to write. Saving the Marketplace half of the form must not
            // fail because of it.
            return true;
        }

        return true;
    }

    /**
     * Cut a raw entry down to what can safely sit in a URL: lower case, letters,
     * digits and single dashes. Returns '' when nothing usable is left, or when
     * the result is too short to be worth publishing.
     */
    public function normalizeSlug(?string $raw): string
    {
        $slug = strtolower(trim((string) $raw));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = (string) preg_replace('/-{2,}/', '-', $slug);

        if (strlen($slug) > self::MAX_SLUG_LENGTH) {
            $slug = rtrim(substr($slug, 0, self::MAX_SLUG_LENGTH), '-');
        }

        return strlen($slug) < self::MIN_SLUG_LENGTH ? '' : $slug;
    }

    /**
     * Whether this company may use this address - free, or already its own.
     */
    public function slugAvailable(string $slug, string $binaryCompanyId): bool
    {
        try {
            $owner = $this->connection->fetchOne(
                'SELECT company_id FROM ' . MarketplaceSetting::TABLE_NAME . ' WHERE share_slug = ?',
                [$slug]
            );
        } catch (Throwable) {
            // Pre-migration deploy window: nobody owns an address yet, and the
            // write below fails safe anyway. Said "free" so the settings page
            // still opens instead of erroring on a column that is on its way.
            return true;
        }

        return $owner === false || $owner === null || $owner === $binaryCompanyId;
    }

    /**
     * A starting address for a business that is switching its page on for the
     * first time, taken from its name and made unique with a number if needed.
     * Falls back to "stock" for a name with nothing usable in it.
     */
    public function suggestSlug(string $companyName, string $binaryCompanyId): string
    {
        $base = $this->normalizeSlug($companyName);

        if ($base === '') {
            $base = 'stock';
        }

        $slug = $base;

        for ($suffix = 2; ! $this->slugAvailable($slug, $binaryCompanyId); ++$suffix) {
            $slug = $base . '-' . $suffix;

            // Guard against an unreachable loop rather than trusting the data.
            if ($suffix > 200) {
                return $base . '-' . substr(bin2hex($binaryCompanyId), 0, 6);
            }
        }

        return $slug;
    }

    /**
     * The company behind a public stock address, or null when the address is
     * unknown or its owner has switched the page off.
     *
     * Deliberately raw DBAL: this runs on an anonymous request, where there is
     * no active company for the usual filter to scope by.
     */
    public function companyIdForSharedStock(string $slug): ?Ulid
    {
        $slug = $this->normalizeSlug($slug);

        if ($slug === '') {
            return null;
        }

        try {
            $companyId = $this->connection->fetchOne(
                'SELECT company_id FROM ' . MarketplaceSetting::TABLE_NAME . ' WHERE share_slug = ? AND share_stock = 1',
                [$slug]
            );
        } catch (Throwable) {
            // Columns not migrated yet - the page 404s until they are, which is
            // the safe way round: it never shows stock nobody opted to publish.
            return null;
        }

        if ($companyId === false || $companyId === null) {
            return null;
        }

        return Ulid::fromBinary((string) $companyId);
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

        // The seller's display picture: their own Marketplace listing logo
        // (app_config key marketplace/logo) if they uploaded one, otherwise a
        // fall-back to the company/invoice logo (system/company/logo). Both are
        // stored as "type|base64". LEFT JOINs so sellers without any logo show.
        $rows = $this->connection->executeQuery(
            "SELECT HEX(c.id) AS vendorId, c.name AS business, c.verified AS verified, sm.name AS model, sm.quantity AS qty,
                    ms.whatsapp AS whatsapp, ms.country AS country, ms.city AS city,
                    COALESCE(NULLIF(mpl.setting_value, ''), col.setting_value) AS logo
             FROM stock_model sm
             INNER JOIN companies c ON c.id = sm.company_id
             INNER JOIN " . MarketplaceSetting::TABLE_NAME . " ms ON ms.company_id = sm.company_id AND ms.listed = 1
             LEFT JOIN app_config mpl ON mpl.company_id = sm.company_id AND mpl.setting_key = 'marketplace/logo'
             LEFT JOIN app_config col ON col.company_id = sm.company_id AND col.setting_key = 'system/company/logo'
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
                // Approved (verified) by the platform owner - drives the tick.
                'verified' => (bool) ($row['verified'] ?? false),
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
                    'verified' => $row['verified'],
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

    /**
     * Where a handful of businesses trade from and how to reach them, in ONE
     * query.
     *
     * The community feed needs this for whoever is posting. Asking per post
     * turned one page into thirty queries, and most of a busy feed is the same
     * few businesses talking anyway.
     *
     * Only businesses that have actually put themselves on the Marketplace come
     * back with a number - somebody who has not opted in should not have their
     * WhatsApp handed out just because they posted.
     *
     * @param list<string> $binaryCompanyIds
     * @return array<string, array{whatsapp: string, chatUrl: string, location: string, flag: string}>
     */
    public function contactsFor(array $binaryCompanyIds): array
    {
        if ($binaryCompanyIds === []) {
            return [];
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT company_id, listed, whatsapp, country, city FROM ' . MarketplaceSetting::TABLE_NAME . ' WHERE company_id IN (?)',
                [$binaryCompanyIds],
                [ArrayParameterType::BINARY]
            );
        } catch (Throwable) {
            return [];
        }

        $contacts = [];

        foreach ($rows as $row) {
            $id = $row['company_id'];

            if (! is_string($id)) {
                continue;
            }

            $whatsapp = (bool) ($row['listed'] ?? false) ? (string) ($row['whatsapp'] ?? '') : '';
            $country = $this->normalizeCountry((string) ($row['country'] ?? ''));

            $contacts[Ulid::fromBinary($id)->toString()] = [
                'whatsapp' => $whatsapp,
                'chatUrl' => $whatsapp === '' ? '' : $this->chatUrl($whatsapp, 'the stock you posted'),
                'location' => $this->location((string) ($row['city'] ?? ''), $country),
                'flag' => $this->flag($country),
            ];
        }

        return $contacts;
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
        $text = "Hi, I got your details from B2BNetwork.ae.\n"
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
