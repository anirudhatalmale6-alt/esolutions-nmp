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

use SolidInvoice\CoreBundle\Action\CreateCompany;
use SolidInvoice\CoreBundle\Action\CreditNote\DeleteCreditNote;
use SolidInvoice\CoreBundle\Action\CreditNote\ListCreditNotes;
use SolidInvoice\CoreBundle\Action\CreditNote\ManageCreditNote;
use SolidInvoice\CoreBundle\Action\DeleteCompany;
use SolidInvoice\CoreBundle\Action\Expense\DeleteExpense;
use SolidInvoice\CoreBundle\Action\Expense\ListExpenses;
use SolidInvoice\CoreBundle\Action\Expense\ManageExpense;
use SolidInvoice\CoreBundle\Action\Search;
use SolidInvoice\CoreBundle\Action\Stock\ImportStock;
use SolidInvoice\CoreBundle\Action\Stock\ListStock;
use SolidInvoice\CoreBundle\Action\Stock\PublicStock;
use SolidInvoice\CoreBundle\Action\Purchase\DeletePurchase;
use SolidInvoice\CoreBundle\Action\Purchase\ListPurchases;
use SolidInvoice\CoreBundle\Action\Purchase\ManagePurchase;
use SolidInvoice\CoreBundle\Action\Purchase\ViewPurchase;
use SolidInvoice\CoreBundle\Action\Report\DailyLedger;
use SolidInvoice\CoreBundle\Action\Report\SalesAnalysis;
use SolidInvoice\CoreBundle\Action\Report\SalesByClient;
use SolidInvoice\CoreBundle\Action\SearchSuggestions;
use SolidInvoice\CoreBundle\Action\SelectCompany;
use SolidInvoice\CoreBundle\Action\ViewBilling;
use SolidInvoice\CoreBundle\Export\Action\DownloadExport;
use SolidInvoice\CoreBundle\Export\Action\ListExports;
use SolidInvoice\CoreBundle\Export\Action\RequestExport;
use Symfony\Bundle\FrameworkBundle\Controller\RedirectController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator
        ->add('_home', '/')
        ->controller([RedirectController::class, 'redirectAction'])
        ->defaults(
            [
                'route' => '_dashboard',
                'permanent' => true,
            ]
        );

    $routingConfigurator
        ->add('_stock_list', '/stock')
        ->controller(ListStock::class);

    $routingConfigurator
        ->add('_stock_import', '/stock/import')
        ->controller(ImportStock::class);

    $routingConfigurator
        ->add('_stock_public', '/nmp-inventory')
        ->controller(PublicStock::class);

    $routingConfigurator
        ->add('_purchases_list', '/purchases')
        ->controller(ListPurchases::class);

    $routingConfigurator
        ->add('_purchase_new', '/purchases/new')
        ->controller(ManagePurchase::class);

    $routingConfigurator
        ->add('_purchase_edit', '/purchases/{id}/edit')
        ->controller(ManagePurchase::class);

    $routingConfigurator
        ->add('_purchase_delete', '/purchases/{id}/delete')
        ->controller(DeletePurchase::class);

    $routingConfigurator
        ->add('_purchase_view', '/purchases/{id}')
        ->controller(ViewPurchase::class);

    $routingConfigurator
        ->add('_expenses_list', '/expenses')
        ->controller(ListExpenses::class);

    $routingConfigurator
        ->add('_expense_new', '/expenses/new')
        ->controller(ManageExpense::class);

    $routingConfigurator
        ->add('_expense_edit', '/expenses/{id}/edit')
        ->controller(ManageExpense::class);

    $routingConfigurator
        ->add('_expense_delete', '/expenses/{id}/delete')
        ->controller(DeleteExpense::class);

    $routingConfigurator
        ->add('_sales_analysis', '/sales')
        ->controller(SalesAnalysis::class);

    $routingConfigurator
        ->add('_sales_by_client', '/sales-by-client')
        ->controller(SalesByClient::class);

    $routingConfigurator
        ->add('_daily_ledger', '/daily-ledger')
        ->controller(DailyLedger::class);

    $routingConfigurator
        ->add('_credit_notes_list', '/credit-notes')
        ->controller(ListCreditNotes::class);

    $routingConfigurator
        ->add('_credit_note_new', '/credit-notes/new/{invoiceId}')
        ->controller(ManageCreditNote::class);

    $routingConfigurator
        ->add('_credit_note_delete', '/credit-notes/{id}/delete')
        ->controller(DeleteCreditNote::class)
        ->methods(['POST']);

    $routingConfigurator
        ->add('_view_quote_external', '/view/quote/{uuid}.{_format}')
        ->controller([ViewBilling::class, 'quoteAction'])
        ->defaults(['_format' => 'html'])
        ->requirements(['uuid' => '[a-zA-Z0-9-]{36}', '_format' => 'html|pdf']);

    $routingConfigurator
        ->add('_view_invoice_external', '/view/invoice/{uuid}.{_format}')
        ->controller([ViewBilling::class, 'invoiceAction'])
        ->defaults(['_format' => 'html'])
        ->requirements(['uuid' => '[a-zA-Z0-9-]{36}', '_format' => 'html|pdf']);

    $routingConfigurator
        ->add('_select_company', '/select-company')
        ->controller(SelectCompany::class);

    $routingConfigurator
        ->add('_switch_company', '/select-company/{id}')
        ->controller([SelectCompany::class, 'switchCompany']);

    $routingConfigurator
        ->add('_create_company', '/create-company')
        ->controller(CreateCompany::class);

    $routingConfigurator
        ->add('_delete_company', '/delete-company')
        ->controller(DeleteCompany::class)
        ->methods(['POST'])
    ;

    $routingConfigurator
        ->add('_search', '/search')
        ->controller(Search::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_search_suggestions', '/search/suggestions')
        ->controller(SearchSuggestions::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_export_list', '/profile/exports')
        ->controller(ListExports::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_export_request', '/profile/exports')
        ->controller(RequestExport::class)
        ->methods(['POST']);

    $routingConfigurator
        ->add('_export_download', '/profile/exports/{id}/download')
        ->controller(DownloadExport::class)
        ->methods(['GET']);
};
