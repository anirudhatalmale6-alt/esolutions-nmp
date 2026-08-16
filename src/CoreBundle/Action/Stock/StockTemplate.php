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

namespace SolidInvoice\CoreBundle\Action\Stock;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The blank stock sheet, for a business that has nothing to export.
 *
 * The importer was written around a Tally "Stock Summary" export, which is the
 * only thing NMP had. Everyone else joining the network has their stock in their
 * head or in a notebook, and no way to know what the upload wants - so hand them
 * the shape of it.
 *
 * The workbook has two sheets: an empty "Stock" sheet, which is the one the
 * importer reads (see {@see \SolidInvoice\CoreBundle\Stock\StockImporter}), and
 * a filled-in "Example" sheet next to it that is never read.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class StockTemplate
{
    private const string HEADING_FILL = 'FF0F172A';

    private const string NOTE_FILL = 'FFF1F5F9';

    public function __invoke(): Response
    {
        $spreadsheet = new Spreadsheet();

        $this->buildStockSheet($spreadsheet->getActiveSheet());
        $this->buildExampleSheet($spreadsheet->createSheet());

        // The importer reads the ACTIVE sheet, so leave the blank one selected -
        // whatever Excel remembers about where the person was last is what gets
        // uploaded back.
        $spreadsheet->setActiveSheetIndex(0);

        $response = new StreamedResponse(static function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'stock-template.xlsx'),
        );
        // A template that arrives out of a browser cache after we change it is
        // worse than no template.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function buildStockSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Stock');

        // Row 1 is the only instruction on this sheet. The importer skips any row
        // whose quantity is not a number, so it can say whatever it needs to.
        $sheet->setCellValue('A1', 'Fill in your stock below and upload this file. One row per item, then a row for each grade of it. Delete this line if you like - it is ignored either way.');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->getFont()->setItalic(true);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::NOTE_FILL);
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(32);

        $this->writeHeadings($sheet, 2);

        // A handful of empty, bordered rows, so it is obvious where to type.
        $sheet->getStyle('A3:D22')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_HAIR);

        $this->sizeColumns($sheet);
        $sheet->setSelectedCell('A3');
    }

    private function buildExampleSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Example');

        $sheet->setCellValue('A1', 'An example. This sheet is never read - it is here to show the shape. An item row carries the TOTAL quantity, and the grade rows under it add up to that total. If you do not grade your stock, just list the items and leave out the grade rows.');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->getFont()->setItalic(true);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::NOTE_FILL);
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(46);

        $this->writeHeadings($sheet, 2);

        $rows = [
            ['SHARP SH-54D SENSE 8', 47, 1230, 57810, true],
            ['A GRADE', 40, 1250, 50000, false],
            ['B GRADE', 4, 1200, 4800, false],
            ['C GRADE', 3, 1003, 3010, false],
            ['SONY SO-51A XPERIA 1 II', 16, 1145, 18320, true],
            ['A GRADE', 16, 1145, 18320, false],
            ['SAMSUNG SCV43 A30', 2, 168, 336, true],
        ];

        $row = 3;

        foreach ($rows as [$name, $quantity, $rate, $value, $isItem]) {
            $sheet->setCellValue('A' . $row, $isItem ? $name : '    ' . $name);
            $sheet->setCellValue('B' . $row, $quantity);
            $sheet->setCellValue('C' . $row, $rate);
            $sheet->setCellValue('D' . $row, $value);

            if ($isItem) {
                $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
            }

            ++$row;
        }

        $sheet->setCellValue('A' . $row, 'The last item has no grades of its own, which is fine.');
        $sheet->mergeCells('A' . $row . ':D' . $row);
        $sheet->getStyle('A' . $row)->getFont()->setItalic(true);

        $sheet->getStyle('A3:D' . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_HAIR);
        $sheet->getStyle('B3:D' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('B3:B' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');

        $this->sizeColumns($sheet);
    }

    private function writeHeadings(Worksheet $sheet, int $row): void
    {
        $headings = [
            'A' => 'Item / Grade',
            'B' => 'Quantity',
            'C' => 'Rate',
            'D' => 'Value',
        ];

        foreach ($headings as $column => $heading) {
            $sheet->setCellValue($column . $row, $heading);
        }

        $style = $sheet->getStyle('A' . $row . ':D' . $row);
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADING_FILL);

        $sheet->freezePane('A' . ($row + 1));
    }

    private function sizeColumns(Worksheet $sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(34);

        foreach (['B', 'C', 'D'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(12);
        }

        // Four columns fit across one page, so a printed copy to write on by
        // hand comes out whole instead of spilling "Value" onto a second sheet.
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
    }
}
