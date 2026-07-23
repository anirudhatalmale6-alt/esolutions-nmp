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
 * Rows are matched to existing entries by IMEI and UPDATED IN PLACE, and files
 * only ADD to the list (they never wipe it), because codes arrive invoice by
 * invoice over time. A separate "clear all" action handles a full reset.
 */
final class UnlockCodeImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UnlockCodeRepository $unlockCodeRepository,
    ) {
    }

    /**
     * @return array{added: int, updated: int, skipped: int, total: int}
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

        // Merge in memory against what the company already has, so a re-upload of
        // an overlapping file updates rows instead of duplicating them.
        $existing = $this->unlockCodeRepository->findAllMap();

        $added = 0;
        $updated = 0;
        $skipped = 0;
        $seen = [];

        foreach ($rows as $row) {
            $imei = $this->normaliseImei((string) ($row[$imeiColumn] ?? ''));

            if ($imei === null) {
                continue;
            }

            $code = trim((string) ($row[$codeColumn] ?? ''));

            // Guard against a header row like "IMEI" / "Key" slipping through, and
            // against the same IMEI appearing twice in the file (last one wins).
            if (isset($seen[$imei])) {
                $entity = $seen[$imei];
                $entity->setCode($code);
                continue;
            }

            if (isset($existing[$imei])) {
                $entity = $existing[$imei];
                $entity->setCode($code);
                ++$updated;
            } else {
                $entity = new UnlockCode();
                $entity->setCompany($company)
                    ->setImei($imei)
                    ->setCode($code);
                $this->entityManager->persist($entity);
                $existing[$imei] = $entity;
                ++$added;
            }

            $seen[$imei] = $entity;
        }

        $this->entityManager->flush();

        return [
            'added' => $added,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => count($seen),
        ];
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
