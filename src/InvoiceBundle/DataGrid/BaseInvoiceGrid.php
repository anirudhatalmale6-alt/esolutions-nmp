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

namespace SolidInvoice\InvoiceBundle\DataGrid;

use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use Override;
use SolidInvoice\DataGridBundle\Grid;
use SolidInvoice\DataGridBundle\GridBuilder\Action\Action;
use SolidInvoice\DataGridBundle\GridBuilder\Action\EditAction;
use SolidInvoice\DataGridBundle\GridBuilder\Action\ViewAction;
use SolidInvoice\DataGridBundle\GridBuilder\Batch\BatchAction;
use SolidInvoice\DataGridBundle\GridBuilder\Column\Column;
use SolidInvoice\DataGridBundle\GridBuilder\Column\MoneyColumn;
use SolidInvoice\DataGridBundle\GridBuilder\Column\RelativeDateColumn;
use SolidInvoice\DataGridBundle\GridBuilder\Column\StringColumn;
use SolidInvoice\DataGridBundle\GridBuilder\Filter\ChoiceFilter;
use SolidInvoice\DataGridBundle\GridBuilder\Filter\DateRangeFilter;
use SolidInvoice\DataGridBundle\GridBuilder\Query;
use SolidInvoice\DataGridBundle\Source\ORMSource;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Enum\InvoiceStatus;
use SolidInvoice\InvoiceBundle\Repository\InvoiceRepository;
use SolidInvoice\InvoiceBundle\Twig\Extension\InvoiceTemplateExtension;
use SolidInvoice\MoneyBundle\Calculator;
use Symfony\Bundle\SecurityBundle\Security;

abstract class BaseInvoiceGrid extends Grid
{
    public function __construct(
        protected readonly Calculator $calculator,
        protected readonly InvoiceTemplateExtension $invoiceTemplateExtension,
        protected readonly Security $security,
    ) {
    }

    public function entityFQCN(): string
    {
        return Invoice::class;
    }

    /**
     * @return Column[]
     */
    #[Override]
    public function columns(): array
    {
        // Column order and set match how the client reads the list day to day:
        // Invoice #, Date, Client, Status, Total, Discount, Paid, Balance.
        // Only the text-ish columns are searchable (invoice number, client name,
        // status); LIKE-searching money/date columns is meaningless and was part
        // of what broke the search box.
        return [
            StringColumn::new('invoiceId')
                ->label('Invoice #'),
            RelativeDateColumn::new('invoiceDate')
                ->searchable(false)
                ->format('d F Y')
                ->filter(new DateRangeFilter('invoiceDate')),
            StringColumn::new('client')
                ->searchField('client.name')
                ->linkToRoute('_clients_view', ['id' => 'client.id']),
            StringColumn::new('status')
                ->twigFunction('invoice_status_label')
                // The status enum has no "partially paid" state, so resolve a
                // payment-aware view (shows "Partially Paid" once a deposit is
                // captured). The view is Stringable, so CSV export stays clean.
                ->formatValue(fn (mixed $value, Invoice $invoice): InvoiceStatusView => $this->invoiceTemplateExtension->invoiceStatusView($invoice))
                ->filter(ChoiceFilter::new('status', array_column(array_map(static fn (InvoiceStatus $s) => [$s->value, $s->getLabel()], InvoiceStatus::cases()), 1, 0))->multiple()),
            MoneyColumn::new('total')
                ->searchable(false)
                ->formatValue(fn (BigNumber $value, Invoice $invoice) => new Money((string) $value, $invoice->getClient()?->getCurrency())),
            MoneyColumn::new('discount.value')
                ->label('Discount')
                ->searchable(false)
                ->formatValue(function (float | BigNumber $value, Invoice $invoice): Money {
                    $discountAmount = $this->calculator->calculateDiscount($invoice);

                    return new Money((string) $discountAmount->toScale(0, RoundingMode::HalfUp), $invoice->getClient()?->getCurrency());
                }),
            MoneyColumn::new('paidAmount')
                ->label('Paid')
                ->searchable(false)
                ->sortable(false)
                ->formatValue(function (mixed $value, Invoice $invoice): Money {
                    // "paidAmount" is a virtual column (no such property), so the
                    // renderer hands us the entity; compute Paid = grand total minus
                    // outstanding balance. Balance is kept in sync with captured
                    // payments, so this stays correct for full, partial and
                    // corrected payments alike.
                    $paid = $invoice->getTotal()->toBigDecimal()->minus($invoice->getBalance()->toBigDecimal());

                    return new Money((string) $paid, $invoice->getClient()?->getCurrency());
                }),
            MoneyColumn::new('balance')
                ->searchable(false)
                ->formatValue(fn (BigNumber $value, Invoice $invoice) => new Money((string) $value, $invoice->getClient()?->getCurrency())),
        ];
    }

    /**
     * @return Action[]
     */
    #[Override]
    public function actions(): array
    {
        $actions = [
            ViewAction::new('_invoices_view', ['id' => 'id']),
        ];

        // Editing is a write action — Managers and up only (Staff is view-only).
        if ($this->security->isGranted('ROLE_MANAGER')) {
            $actions[] = EditAction::new('_invoices_edit', ['id' => 'id']);
        }

        return $actions;
    }

    #[Override]
    public function batchActions(): iterable
    {
        return [];
    }

    #[Override]
    public function query(EntityManagerInterface $entityManager, Query $query): Query
    {
        $query->getQueryBuilder()->orderBy(ORMSource::ALIAS . '.invoiceDate', 'DESC');

        return $query;
    }
}
