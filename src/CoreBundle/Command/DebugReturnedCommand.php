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

namespace SolidInvoice\CoreBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Repository\CreditNoteRepository;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Repository\InvoiceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Throwaway diagnostic: dumps exactly what the invoice view computes for the
 * "returned qty" display, so we can see at runtime why a line isn't showing the
 * returned units. Safe, read-only. Delete after use.
 */
#[AsCommand(
    name: 'app:debug:returned',
    description: 'Diagnostic: show returned-line matching for an invoice.',
)]
final class DebugReturnedCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CreditNoteRepository $creditNoteRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('invoiceNumber', InputArgument::REQUIRED, 'The human invoice number, e.g. NMP-36-2026');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $number = (string) $input->getArgument('invoiceNumber');

        $filters = $this->entityManager->getFilters();
        $io->section('Doctrine SQL filters enabled');
        $enabled = $filters->getEnabledFilters();
        $io->writeln($enabled === [] ? '(none)' : implode(', ', array_keys($enabled)));

        $invoice = $this->invoiceRepository->findOneBy(['invoiceId' => $number]);

        if (! $invoice instanceof Invoice) {
            $io->error(sprintf('Invoice "%s" not found (lookup by invoiceId).', $number));

            return Command::FAILURE;
        }

        $io->section('Invoice');
        $io->writeln('id (base32): ' . (string) $invoice->getId());
        $io->writeln('company id : ' . ($invoice->getCompany() ? (string) $invoice->getCompany()->getId() : '(null)'));

        $io->section('Invoice lines (id as the template stringifies it)');
        $lineIds = [];
        foreach ($invoice->getLines() as $line) {
            $idStr = $line->getId() . '';
            $lineIds[] = $idStr;
            $io->writeln(sprintf('%s | qty=%s | %s', $idStr, (string) $line->getQty(), (string) $line->getDescription()));
        }

        $creditNotes = $this->creditNoteRepository->findForInvoice($invoice);
        $io->section('Credit notes via findForInvoice() (same call the page uses)');
        $io->writeln('count: ' . count($creditNotes));

        $returnedByLine = [];
        foreach ($creditNotes as $creditNote) {
            $io->writeln('--- credit note ' . (string) $creditNote->getId() . ' | company=' . ($creditNote->getCompany() ? (string) $creditNote->getCompany()->getId() : '(null)') . ' | amount=' . $creditNote->getAmount());
            $rl = $creditNote->getReturnedLines();
            $io->writeln('    getReturnedLines() = ' . json_encode($rl));
            foreach ($rl as $lineId => $qty) {
                $returnedByLine[$lineId] = ($returnedByLine[$lineId] ?? 0.0) + (float) $qty;
            }
        }

        $io->section('returnedByLine map keys');
        foreach ($returnedByLine as $k => $v) {
            $io->writeln(sprintf('[%s] => %s', $k, (string) $v));
        }

        $io->section('MATCH TEST (does each line find a returned qty?)');
        foreach ($lineIds as $idStr) {
            $hit = $returnedByLine[$idStr] ?? null;
            $io->writeln(sprintf('line %s : %s', $idStr, $hit === null ? 'NO MATCH' : 'MATCH -> ' . $hit));
        }

        return Command::SUCCESS;
    }
}
