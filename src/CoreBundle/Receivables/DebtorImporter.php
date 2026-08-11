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

namespace SolidInvoice\CoreBundle\Receivables;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\ClientBundle\Repository\ClientRepository;
use SolidInvoice\CoreBundle\Entity\Company;
use function is_numeric;
use function preg_replace;
use function str_contains;
use function strtoupper;
use function trim;

/**
 * Reads a Tally "Sundry Debtors - Group Summary" Excel export and turns each
 * ledger line into a carried-over opening balance on the matching customer.
 *
 * The Tally layout is three columns - Particulars, Debit, Credit - with a few
 * heading rows on top (company name, report name, date range) and a Grand Total
 * row at the bottom. A Debit closing balance means the customer owes us; a
 * Credit balance means we owe them (or they have paid in advance), so it is
 * carried over as a negative opening balance.
 *
 * The sheet also holds rows that are NOT customers - staff loans, visa costs,
 * cargo payables and shop deposits. Those are flagged so they arrive unticked on
 * the preview screen and are only imported if the user deliberately ticks them.
 */
final class DebtorImporter
{
    /**
     * Words that mark a ledger line as something other than a trading customer.
     * Matched on the upper-cased name; used only to pre-tick the preview, never
     * to silently drop a row.
     */
    private const array NON_CUSTOMER_HINTS = [
        'LOAN',
        'STAFF',
        'ADVANCE',
        'VISA',
        'EXP',
        'PAYABLE',
        'DEPOSIT',
        'SALARY',
        'RENT',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientRepository $clientRepository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Parse the spreadsheet into preview rows, without touching the database.
     *
     * @return list<array{name: string, debit: string, credit: string, balance: string, isCustomer: bool, existing: bool, existingBalance: ?string}>
     */
    public function parse(string $filePath, Company $company): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);

        $existing = $this->existingClientNames($company);
        $parsed = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row[0] ?? ''));

            if ($name === '') {
                continue;
            }

            $debit = $this->toAmount($row[1] ?? null);
            $credit = $this->toAmount($row[2] ?? null);

            // Heading rows carry no figures; the Grand Total row is a summary,
            // not a ledger account.
            if ($debit === null && $credit === null) {
                continue;
            }

            if ($this->isTotalRow($name)) {
                continue;
            }

            $balance = BigDecimal::of($debit ?? '0')->minus(BigDecimal::of($credit ?? '0'));
            $key = $this->normalise($name);

            $parsed[] = [
                'name' => $name,
                'debit' => $debit ?? '0.00',
                'credit' => $credit ?? '0.00',
                'balance' => (string) $balance->toScale(2),
                'isCustomer' => ! $this->looksLikeNonCustomer($name),
                'existing' => isset($existing[$key]),
                'existingBalance' => $existing[$key] ?? null,
            ];
        }

        return $parsed;
    }

    /**
     * Apply the chosen rows: match each name to a customer (creating one if it
     * does not exist yet) and write the carried-over balance onto it.
     *
     * @param list<array{name: string, balance: string}> $rows
     *
     * @return array{updated: int, created: int, total: string}
     */
    public function import(array $rows, Company $company): array
    {
        $byName = [];

        foreach ($this->clientRepository->findBy(['company' => $company]) as $client) {
            $byName[$this->normalise((string) $client->getName())] = $client;
        }

        $updated = 0;
        $created = 0;
        $total = BigDecimal::zero();

        foreach ($rows as $row) {
            $name = trim($row['name']);

            if ($name === '') {
                continue;
            }

            $balance = BigDecimal::of($row['balance'])->toScale(2);
            $key = $this->normalise($name);
            $client = $byName[$key] ?? null;

            if (! $client instanceof Client) {
                $client = new Client();
                $client->setName($name);
                $client->setCompany($company);
                $this->entityManager->persist($client);
                $byName[$key] = $client;
                ++$created;
            } else {
                ++$updated;
            }

            $client->setOpeningBalance((string) $balance);
            $total = $total->plus($balance);
        }

        $this->entityManager->flush();

        return [
            'updated' => $updated,
            'created' => $created,
            'total' => (string) $total->toScale(2),
        ];
    }

    /**
     * Existing customers of this company, keyed by normalised name, with their
     * current opening balance - so the preview can show what a row would change.
     *
     * @return array<string, string>
     */
    private function existingClientNames(Company $company): array
    {
        $companyId = $company->getId();

        if ($companyId === null) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            'SELECT name, opening_balance FROM clients WHERE company_id = :companyId',
            ['companyId' => $companyId->toBinary()],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $names = [];

        foreach ($rows as $row) {
            $names[$this->normalise((string) $row['name'])] = (string) ($row['opening_balance'] ?? '0.00');
        }

        return $names;
    }

    /**
     * Tally writes amounts as numbers, but an exported sheet can hold them as
     * text with thousands separators. Returns null when the cell holds no figure.
     */
    private function toAmount(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim((string) $value);

        if ($clean === '') {
            return null;
        }

        $clean = (string) preg_replace('/[,\s]/', '', $clean);

        if (! is_numeric($clean)) {
            return null;
        }

        return (string) BigDecimal::of($clean)->toScale(2);
    }

    private function isTotalRow(string $name): bool
    {
        $upper = strtoupper($name);

        return str_contains($upper, 'GRAND TOTAL')
            || $upper === 'TOTAL'
            || str_contains($upper, 'OPENING BALANCE')
            || str_contains($upper, 'CLOSING BALANCE');
    }

    private function looksLikeNonCustomer(string $name): bool
    {
        $upper = strtoupper($name);

        foreach (self::NON_CUSTOMER_HINTS as $hint) {
            if (str_contains($upper, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match names regardless of case, spacing or punctuation, so "A N M" and
     * "ANM" land on the same customer.
     */
    private function normalise(string $name): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
    }
}
