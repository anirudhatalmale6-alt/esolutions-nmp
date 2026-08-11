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
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\ClientBundle\Repository\ClientRepository;
use SolidInvoice\CoreBundle\Entity\Company;
use Throwable;
use function bin2hex;
use function is_numeric;
use function max;
use function min;
use function preg_match;
use function preg_replace;
use function similar_text;
use function str_contains;
use function strlen;
use function strtolower;
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

    /**
     * How close two names must be before a match is offered as the default.
     * Below this the row falls back to "create a new customer" rather than
     * putting a wrong guess in front of the user.
     */
    private const int MATCH_THRESHOLD = 72;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientRepository $clientRepository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Parse the spreadsheet into preview rows, without touching the database.
     *
     * Each row also carries a suggested match against the customers already on
     * the system, because the names in Tally rarely match the names here
     * exactly. The suggestion is only ever a default for the preview dropdown -
     * the user picks the real match before anything is written.
     *
     * @return array{rows: list<array{name: string, debit: string, credit: string, balance: string, isCustomer: bool, matchId: ?string, matchName: ?string, matchScore: int, matchExact: bool, matchBalance: ?string, b2bUnpaid: string, b2bReceipts: string, opening: string}>, asOf: string}
     */
    public function parse(string $filePath, Company $company, ?DateTimeInterface $asOf = null): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);

        // Tally prints the period across the top ("1-Jan-26 to 10-Aug-26"). The
        // closing date is what the balances are true as at, so read it rather
        // than making the user retype it.
        $asOf ??= $this->detectAsOfDate($rows) ?? new DateTimeImmutable('today');

        $existing = $this->clients($company);
        $position = $this->positionAsOf($company, $asOf);
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
            $match = $this->bestMatch($name, $existing);

            // What B2B Network already knew about this customer on the sheet's
            // date. The sheet's closing balance already contains both, so both
            // have to be unwound or the same money is counted twice - the
            // invoices double-charged, the receipts double-credited.
            $known = $match['id'] !== null
                ? ($position[$match['id']] ?? ['unpaid' => '0.00', 'receipts' => '0.00'])
                : ['unpaid' => '0.00', 'receipts' => '0.00'];

            $alreadyOpen = BigDecimal::of($known['unpaid']);
            $alreadyPaid = BigDecimal::of($known['receipts']);

            $parsed[] = [
                'name' => $name,
                'debit' => $debit ?? '0.00',
                'credit' => $credit ?? '0.00',
                'balance' => (string) $balance->toScale(2),
                'isCustomer' => ! $this->looksLikeNonCustomer($name),
                'matchId' => $match['id'],
                'matchName' => $match['name'],
                'matchScore' => $match['score'],
                'matchExact' => $match['exact'],
                'matchBalance' => $match['balance'],
                'b2bUnpaid' => (string) $alreadyOpen->toScale(2),
                'b2bReceipts' => (string) $alreadyPaid->toScale(2),
                'opening' => (string) $balance->minus($alreadyOpen)->plus($alreadyPaid)->toScale(2),
            ];
        }

        return [
            'rows' => $parsed,
            'asOf' => $asOf->format('Y-m-d'),
        ];
    }

    /**
     * Pull the closing date out of the Tally period heading, e.g.
     * "1-Jan-26 to 10-Aug-26" -> 2026-08-10. Returns null if it is not there,
     * in which case the caller falls back to today and the user can correct it.
     */
    private function detectAsOfDate(array $rows): ?DateTimeImmutable
    {
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if ($cell === null) {
                    continue;
                }

                $text = trim((string) $cell);

                if ($text === '' || ! preg_match('/(\d{1,2}-[A-Za-z]{3}-\d{2,4})\s*to\s*(\d{1,2}-[A-Za-z]{3}-\d{2,4})/i', $text, $matches)) {
                    continue;
                }

                try {
                    return new DateTimeImmutable($matches[2]);
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Every customer on the system for this company, for the preview dropdown.
     *
     * @return list<array{id: string, name: string, balance: string}>
     */
    public function clients(Company $company): array
    {
        $companyId = $company->getId();

        if ($companyId === null) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            'SELECT LOWER(HEX(id)) AS id, name, opening_balance AS balance
             FROM clients
             WHERE company_id = :companyId
               AND (archived IS NULL OR archived = 0)
             ORDER BY name ASC',
            ['companyId' => $companyId->toBinary()],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $clients = [];

        foreach ($rows as $row) {
            $clients[] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'balance' => (string) ($row['balance'] ?? '0.00'),
            ];
        }

        return $clients;
    }

    /**
     * Rebuild what B2B Network knew about each customer AS AT a given date - the
     * date the Tally sheet was run.
     *
     * This has to be the position on that date, not today's, because the sheet's
     * closing balance already contains everything that had happened by then. Two
     * figures per customer, both in major units:
     *
     *   unpaid    invoices raised on or before the date, less the payments
     *             captured against them on or before the date
     *   receipts  standalone "Payment In" receipts dated on or before the date
     *
     * Today's balances are deliberately not used: a payment taken after the
     * sheet was run must still count against the customer, not be swallowed
     * into the opening balance.
     *
     * @return array<string, array{unpaid: string, receipts: string}>
     */
    public function positionAsOf(Company $company, DateTimeInterface $asOf): array
    {
        $companyId = $company->getId();

        if ($companyId === null) {
            return [];
        }

        $binaryCompanyId = $companyId->toBinary();
        $date = $asOf->format('Y-m-d');
        $position = [];

        $add = function (string $id, string $key, string $value) use (&$position): void {
            if (! isset($position[$id])) {
                $position[$id] = ['unpaid' => '0.00', 'receipts' => '0.00'];
            }

            $position[$id][$key] = (string) BigDecimal::of($position[$id][$key])->plus(BigDecimal::of($value))->toScale(2);
        };

        // What they had been billed by that date.
        $invoices = $this->connection->executeQuery(
            "SELECT LOWER(HEX(client_id)) AS id, SUM(total_amount) AS total
             FROM invoices
             WHERE company_id = :companyId
               AND (archived IS NULL OR archived = 0)
               AND status NOT IN ('draft', 'cancelled', 'archived')
               AND invoice_date <= :asOf
             GROUP BY client_id",
            ['companyId' => $binaryCompanyId, 'asOf' => $date],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        foreach ($invoices as $row) {
            $add((string) $row['id'], 'unpaid', $this->fromMinor((string) ($row['total'] ?? '0')));
        }

        // ...less what they had paid against those invoices by that date.
        $payments = $this->connection->executeQuery(
            "SELECT LOWER(HEX(client)) AS id, SUM(total_amount) AS total
             FROM payments
             WHERE company_id = :companyId
               AND status = 'captured'
               AND client IS NOT NULL
               AND completed IS NOT NULL
               AND DATE(completed) <= :asOf
             GROUP BY client",
            ['companyId' => $binaryCompanyId, 'asOf' => $date],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        foreach ($payments as $row) {
            $paid = $this->fromMinor((string) ($row['total'] ?? '0'));
            $add((string) $row['id'], 'unpaid', (string) BigDecimal::of($paid)->negated());
        }

        // Standalone receipts already reflected in the sheet's closing balance.
        $receipts = $this->connection->executeQuery(
            'SELECT LOWER(HEX(client_id)) AS id, SUM(amount) AS total
             FROM customer_receipt
             WHERE company_id = :companyId
               AND client_id IS NOT NULL
               AND receipt_date <= :asOf
             GROUP BY client_id',
            ['companyId' => $binaryCompanyId, 'asOf' => $date],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        foreach ($receipts as $row) {
            $total = (string) ($row['total'] ?? '0');
            $add((string) $row['id'], 'receipts', is_numeric($total) ? $total : '0.00');
        }

        return $position;
    }

    /**
     * Minor units (fils, as an integer string) -> major units with 2 decimals.
     */
    private function fromMinor(string $minor): string
    {
        return is_numeric($minor)
            ? (string) BigDecimal::of($minor)->dividedBy(100, 2, RoundingMode::HalfUp)
            : '0.00';
    }

    /**
     * Clear every carried-over opening balance for this company, putting the
     * debtors report back to invoices-and-payments only. This is the undo for a
     * mis-run import, and it touches nothing except the opening balance.
     */
    public function reset(Company $company): int
    {
        $companyId = $company->getId();

        if ($companyId === null) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            "UPDATE clients SET opening_balance = '0.00'
             WHERE company_id = :companyId
               AND opening_balance <> '0.00'",
            ['companyId' => $companyId->toBinary()],
            ['companyId' => ParameterType::BINARY]
        );
    }

    /**
     * Find the customer a Tally ledger name most likely refers to.
     *
     * An exact match (ignoring case, spaces and punctuation) always wins. After
     * that it looks for one name containing the other - "SAYED BHAI" against
     * "Sayed Bhai Mobile Trading" - and finally falls back to a similarity
     * score. Anything below the threshold returns no suggestion rather than a
     * bad guess, so the row defaults to "create a new customer".
     *
     * @param list<array{id: string, name: string, balance: string}> $clients
     *
     * @return array{id: ?string, name: ?string, score: int, exact: bool, balance: ?string}
     */
    private function bestMatch(string $name, array $clients): array
    {
        $needle = $this->normalise($name);

        if ($needle === '') {
            return ['id' => null, 'name' => null, 'score' => 0, 'exact' => false, 'balance' => null];
        }

        $best = null;
        $bestScore = 0;

        foreach ($clients as $client) {
            $candidate = $this->normalise($client['name']);

            if ($candidate === '') {
                continue;
            }

            if ($candidate === $needle) {
                return [
                    'id' => $client['id'],
                    'name' => $client['name'],
                    'score' => 100,
                    'exact' => true,
                    'balance' => $client['balance'],
                ];
            }

            // One name sitting inside the other is a strong signal, but only
            // when the shorter side is long enough that it is not a coincidence.
            $contains = (str_contains($candidate, $needle) || str_contains($needle, $candidate))
                && min(strlen($candidate), strlen($needle)) >= 4;

            $percent = 0.0;
            similar_text($needle, $candidate, $percent);
            $score = $contains ? max(90, (int) $percent) : (int) $percent;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $client;
            }
        }

        if ($best === null || $bestScore < self::MATCH_THRESHOLD) {
            return ['id' => null, 'name' => null, 'score' => $bestScore, 'exact' => false, 'balance' => null];
        }

        return [
            'id' => $best['id'],
            'name' => $best['name'],
            'score' => $bestScore,
            'exact' => false,
            'balance' => $best['balance'],
        ];
    }

    /**
     * Apply the chosen rows and write the carried-over balance onto each one.
     *
     * Every row states which customer it belongs to: a clientId picked on the
     * preview links the Tally line to an existing customer whatever their name
     * is here, and an empty clientId means "create a new customer under the
     * Tally name". Nothing is guessed at this stage - the choice was already
     * made and shown on screen.
     *
     * $basis says what the figures in the sheet actually mean:
     *
     *   'total'    the Tally balance is the customer's FULL balance as at the
     *              sheet's date, so everything B2B Network already knew on that
     *              date is unwound: unpaid invoices come off, and receipts
     *              already taken go back on. What is left is the genuinely old
     *              debt, and opening + invoices - receipts then lands back on
     *              the sheet figure.
     *   'old_only' the sheet holds only what predates B2B Network, so it is
     *              carried over as-is.
     *
     * @param list<array{name: string, balance: string, clientId: string}> $rows
     *
     * @return array{updated: int, created: int, total: string, adjusted: int}
     */
    public function import(array $rows, Company $company, string $basis = 'total', ?DateTimeInterface $asOf = null): array
    {
        $position = $basis === 'total'
            ? $this->positionAsOf($company, $asOf ?? new DateTimeImmutable('today'))
            : [];
        $byId = [];

        foreach ($this->clientRepository->findBy(['company' => $company]) as $client) {
            $id = $client->getId();

            if ($id !== null) {
                $byId[bin2hex($id->toBinary())] = $client;
            }
        }

        // Two Tally lines can legitimately point at one customer here (an old
        // and a current account, say), so collect by target first and add the
        // balances up. Writing them one after another would silently keep only
        // the last line's figure.
        $targets = [];

        foreach ($rows as $row) {
            $name = trim($row['name']);

            if ($name === '') {
                continue;
            }

            $clientId = strtolower(trim($row['clientId']));
            $key = $clientId !== '' ? 'id:' . $clientId : 'new:' . $this->normalise($name);

            if (! isset($targets[$key])) {
                $targets[$key] = ['name' => $name, 'clientId' => $clientId, 'balance' => BigDecimal::zero()];
            }

            $targets[$key]['balance'] = $targets[$key]['balance']->plus(BigDecimal::of($row['balance']));
        }

        $updated = 0;
        $created = 0;
        $adjusted = 0;
        $total = BigDecimal::zero();

        foreach ($targets as $target) {
            $balance = $target['balance']->toScale(2);
            $client = $target['clientId'] !== '' ? ($byId[$target['clientId']] ?? null) : null;

            // Unwind whatever B2B Network already knew on the sheet's date, so
            // that opening + invoices - receipts lands exactly on the Tally
            // figure instead of charging or crediting the same money twice.
            if ($target['clientId'] !== '' && isset($position[$target['clientId']])) {
                $known = $position[$target['clientId']];
                $open = BigDecimal::of($known['unpaid']);
                $paid = BigDecimal::of($known['receipts']);

                if (! $open->isZero() || ! $paid->isZero()) {
                    $balance = $balance->minus($open)->plus($paid)->toScale(2);
                    ++$adjusted;
                }
            }

            if ($client instanceof Client) {
                ++$updated;
            } else {
                $client = new Client();
                $client->setName($target['name']);
                $client->setCompany($company);
                $this->entityManager->persist($client);
                ++$created;
            }

            $client->setOpeningBalance((string) $balance);
            $total = $total->plus($balance);
        }

        $this->entityManager->flush();

        return [
            'updated' => $updated,
            'created' => $created,
            'total' => (string) $total->toScale(2),
            'adjusted' => $adjusted,
        ];
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
