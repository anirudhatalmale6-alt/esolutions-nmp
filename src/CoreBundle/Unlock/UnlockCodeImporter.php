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

namespace SolidInvoice\CoreBundle\Unlock;

use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\UnlockCode;
use SolidInvoice\CoreBundle\Repository\UnlockCodeRepository;
use function count;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * Parses a supplier "unlocking codes" Excel sheet and merges its IMEI -> code
 * pairs into the current company's unlock-code list.
 *
 * The suppliers send these sheets in several different layouts - some have
 * Model / Memory / Grade columns, one is just IMEI + Key - but in every one of
 * them the code sits in the column immediately to the RIGHT of the IMEI. So the
 * importer locates the IMEI column automatically (the column that holds the most
 * 14-17 digit numbers) and reads the code from the next column across. That way
 * the owner uploads each file exactly as received, with no reformatting.
 *
 * Some suppliers instead send the raw unlock-service LOG rather than a clean
 * IMEI+code sheet. In those the same IMEI appears on many rows - one per attempt
 * - and most rows are error responses ("NOT SUPPORT", "Wrong IMEI", "device is
 * not eligible", blank...). The single successful row carries the code, and in
 * that row the code cell is written as "<imei> <code>" (the IMEI repeated, then
 * the code). So for each IMEI we keep the best answer across all its rows: a real
 * code always beats an error message, and where the cell repeats the IMEI we drop
 * that prefix and store only the code.
 *
 * Rows are matched to existing entries by IMEI and UPDATED IN PLACE, and files
 * only ADD codes (they never wipe the list), because codes arrive invoice by
 * invoice over time. The one exception is self-healing: if a re-upload shows only
 * error responses for an IMEI whose stored value is itself a leftover error (not
 * a real code), that stale junk row is removed - a real code is never removed.
 */
final class UnlockCodeImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UnlockCodeRepository $unlockCodeRepository,
    ) {
    }

    /**
     * @return array{added: int, updated: int, removed: int, skipped: int, total: int}
     */
    public function import(string $filePath, Company $company): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        // formatData = true so IMEIs and codes come back as the text they are
        // shown as. Long IMEI/code strings would lose their last digits if read
        // as floating point numbers, so we never want the raw numeric value.
        $rows = $spreadsheet->getActiveSheet()->toArray(null, false, true, false);

        $imeiColumn = $this->detectImeiColumn($rows);

        if ($imeiColumn === null) {
            throw new RuntimeException('No column of IMEI numbers was found in this file.');
        }

        $codeColumn = $imeiColumn + 1;

        // Collapse the file to the single best answer per IMEI first. A real code
        // beats any error message, so a supplier log where an IMEI has one good
        // row and several error rows resolves to the good code.
        //
        // @var array<string, array{code: string, real: bool}> $best
        $best = [];

        foreach ($rows as $row) {
            $imei = $this->normaliseImei((string) ($row[$imeiColumn] ?? ''));

            if ($imei === null) {
                continue;
            }

            [$code, $real] = $this->evaluateCode($imei, (string) ($row[$codeColumn] ?? ''));

            // Keep the new answer when it is a real code, or when we have nothing
            // better yet (the current best is not a real code either).
            if (! isset($best[$imei]) || $real || ! $best[$imei]['real']) {
                $best[$imei] = ['code' => $code, 'real' => $real];
            }
        }

        // Merge against what the company already has, so a re-upload updates rows
        // instead of duplicating them.
        $existing = $this->unlockCodeRepository->findAllMap();

        $added = 0;
        $updated = 0;
        $removed = 0;
        $skipped = 0;

        foreach ($best as $imei => $info) {
            // PHP casts an all-digit array key to int, so $imei arrives here as an
            // int for numeric IMEIs. Force it back to a string before it reaches
            // the entity (setImei() is typed string) or a lookup.
            $imei = (string) $imei;
            $current = $existing[$imei] ?? null;

            if ($info['real']) {
                if ($current !== null) {
                    if ($current->getCode() !== $info['code']) {
                        $current->setCode($info['code']);
                        ++$updated;
                    } else {
                        ++$skipped;
                    }
                } else {
                    $entity = new UnlockCode();
                    $entity->setCompany($company)
                        ->setImei($imei)
                        ->setCode($info['code']);
                    $this->entityManager->persist($entity);
                    ++$added;
                }

                continue;
            }

            // Not a real code: never insert it. If a stale junk row is sitting in
            // the DB for this IMEI (a leftover error, not a real code), clear it
            // out; but a genuine code already on file is left untouched.
            if ($current !== null && ! $this->isRealCode($current->getCode())) {
                $this->entityManager->remove($current);
                ++$removed;
            } else {
                ++$skipped;
            }
        }

        $this->entityManager->flush();

        return [
            'added' => $added,
            'updated' => $updated,
            'removed' => $removed,
            'skipped' => $skipped,
            'total' => count($best),
        ];
    }

    /**
     * Turn a raw "code" cell for a given IMEI into the code to store, plus a flag
     * for whether it is a real deliverable code (as opposed to an error/blank).
     *
     * Handles the supplier-log format where the cell repeats the IMEI in front of
     * the code ("<imei> <code>") by dropping that prefix.
     *
     * @return array{0: string, 1: bool}
     */
    private function evaluateCode(string $imei, string $raw): array
    {
        $value = trim($raw);

        // Drop a leading copy of the IMEI, e.g. "353674070701631 3073350715757498".
        if ($value !== '' && str_starts_with($value, $imei)) {
            $value = trim(substr($value, strlen($imei)));
        }

        return [$value, $this->isRealCode($value)];
    }

    /**
     * A "real" answer is either an authoritative supplier STATUS (SIM Free /
     * Unlocked / "Locked (Cannot provide unlock code)"...) or a single token that
     * looks like an unlock code - digits (with optional letters/dashes), 5-40
     * chars, at least one digit. Everything else (blank cells and transient
     * service-log errors like "NOT SUPPORT", "Wrong IMEI", "device is not
     * eligible"...) is not a deliverable answer.
     */
    private function isRealCode(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if ($this->matchesStatus($value)) {
            return true;
        }

        // A code is a single token (no spaces) with at least one digit.
        return preg_match('/^[A-Za-z0-9-]{5,40}$/', $value) === 1
            && preg_match('/\d/', $value) === 1;
    }

    /**
     * Decide whether a free-text cell is an authoritative supplier status worth
     * storing (so the customer sees "SIM Free" / "device is locked" instead of
     * "no code found"), as opposed to a transient service-log error.
     *
     * Error phrases are checked FIRST so anything like "device is not eligible,
     * locked bootloader" is rejected before the "locked" status match can catch
     * it.
     */
    private function matchesStatus(string $value): bool
    {
        $value = strtolower(trim($value));

        foreach (['not support', 'notsupport', 'not supported', 'wrong', 'not eligible', 'ineligible', 'invalid', 'fail', 'error', 'retry', 'try again', 'pending', 'processing', 'unavailable', 'no result', 'not found', 'reject'] as $bad) {
            if (str_contains($value, $bad)) {
                return false;
            }
        }

        foreach (['sim free', 'simfree', 'unlocked', 'already unlocked', 'locked'] as $good) {
            if (str_contains($value, $good)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a raw cell to a bare IMEI (digits only, 14-17 long) or null if it is
     * not an IMEI - which also drops header rows and blank cells for free.
     */
    private function normaliseImei(string $value): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);

        return preg_match('/^\d{14,17}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Find the column that holds IMEIs: the one with the most 14-17 digit values.
     *
     * @param array<int, array<int, mixed>> $rows
     */
    private function detectImeiColumn(array $rows): ?int
    {
        $counts = [];

        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                if ($this->normaliseImei((string) ($value ?? '')) !== null) {
                    $counts[$index] = ($counts[$index] ?? 0) + 1;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        $best = null;
        $bestCount = 0;

        foreach ($counts as $index => $count) {
            if ($count > $bestCount) {
                $best = $index;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
