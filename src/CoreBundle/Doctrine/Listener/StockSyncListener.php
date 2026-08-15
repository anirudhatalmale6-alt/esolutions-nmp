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

namespace SolidInvoice\CoreBundle\Doctrine\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use SolidInvoice\CoreBundle\Entity\CreditNote;
use SolidInvoice\CoreBundle\Entity\Purchase;
use SolidInvoice\CoreBundle\Entity\PurchaseItem;
use SolidInvoice\CoreBundle\Enum\StockMovementReason;
use SolidInvoice\CoreBundle\Stock\StockPoster;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Entity\Line;
use Throwable;

/**
 * Keeps stock in step with the documents that move it, whichever screen the
 * change came from.
 *
 * This deliberately hooks Doctrine rather than the half-dozen controllers that
 * can touch an invoice - the edit form, the send/cancel/pay buttons, the grid's
 * bulk actions, the API and the recurring-invoice run all end at the same
 * flush. Hooking one place means no path can quietly skip stock, which is the
 * failure that makes an inventory system untrustworthy.
 *
 * Work is collected during onFlush (while the unit of work still knows what
 * changed, and while a deleted record's id can still be read) and carried out
 * in postFlush, once the documents themselves are safely written.
 */
#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
final class StockSyncListener
{
    /** @var list<Invoice|Purchase|CreditNote> */
    private array $pending = [];

    /** @var list<array{type: string, id: string, reason: StockMovementReason, note: string}> */
    private array $removed = [];

    /** Guards against the listener reacting to its own movement rows. */
    private bool $posting = false;

    public function __construct(
        private readonly StockPoster $poster,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->posting) {
            return;
        }

        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $this->queueDeletion($entity);
        }

        foreach ([
            $unitOfWork->getScheduledEntityInsertions(),
            $unitOfWork->getScheduledEntityUpdates(),
        ] as $entities) {
            foreach ($entities as $entity) {
                $this->queue($entity);
            }
        }

        // A line can be added or dropped without the document itself counting
        // as changed, so collection changes are followed back to their owner.
        foreach ([
            $unitOfWork->getScheduledCollectionUpdates(),
            $unitOfWork->getScheduledCollectionDeletions(),
        ] as $collections) {
            foreach ($collections as $collection) {
                $this->queue($collection->getOwner());
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->posting || ($this->pending === [] && $this->removed === [])) {
            return;
        }

        $pending = $this->pending;
        $removed = $this->removed;
        $this->pending = [];
        $this->removed = [];

        // Anything being deleted has the last word: deleting an invoice also
        // deletes its lines, and those line deletions would otherwise queue the
        // invoice to be re-posted from a record that no longer exists.
        $deletedIds = [];

        foreach ($removed as $entry) {
            $deletedIds[$entry['type'] . '|' . $entry['id']] = true;
        }

        $this->posting = true;

        try {
            $written = 0;

            foreach ($removed as $entry) {
                $written += $this->poster->removeSource($entry['type'], $entry['id'], $entry['reason'], $entry['note'], flush: false);
            }

            foreach ($pending as $entity) {
                $key = $this->sourceType($entity) . '|' . $entity->getId();

                if (isset($deletedIds[$key])) {
                    continue;
                }

                $written += match (true) {
                    $entity instanceof Invoice => $this->poster->postInvoice($entity, flush: false),
                    $entity instanceof Purchase => $this->poster->postPurchase($entity, flush: false),
                    $entity instanceof CreditNote => $this->poster->postCreditNote($entity, flush: false),
                };
            }

            if ($written > 0) {
                $args->getObjectManager()->flush();
            }
        } catch (Throwable $e) {
            // Stock must never be the reason an invoice cannot be saved. If a
            // movement fails to post, the document still stands and the drift
            // shows up on the stock page, where it can be corrected by hand -
            // but it is logged, because silent drift is how an inventory system
            // stops being believed.
            $this->logger->error('Could not post stock movements: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        } finally {
            $this->posting = false;
        }
    }

    private function queue(mixed $entity): void
    {
        $document = match (true) {
            $entity instanceof Invoice, $entity instanceof Purchase, $entity instanceof CreditNote => $entity,
            $entity instanceof Line => $entity->getInvoice(),
            $entity instanceof PurchaseItem => $entity->getPurchase(),
            default => null,
        };

        if ($document === null || $document->getId() === null || ! $this->tracksStock($document)) {
            return;
        }

        foreach ($this->pending as $queued) {
            if ($queued === $document) {
                return;
            }
        }

        $this->pending[] = $document;
    }

    private function queueDeletion(mixed $entity): void
    {
        // A deleted line or item is not a deleted document - the document that
        // owns it is simply smaller now, and re-posting it writes the change.
        if ($entity instanceof Line || $entity instanceof PurchaseItem) {
            $this->queue($entity);

            return;
        }

        [$reason, $note] = match (true) {
            $entity instanceof Invoice => [StockMovementReason::Sale, 'Invoice deleted'],
            $entity instanceof Purchase => [StockMovementReason::Purchase, 'Purchase deleted'],
            $entity instanceof CreditNote => [StockMovementReason::Return, 'Refund deleted'],
            default => [null, null],
        };

        if ($reason === null || ! $this->tracksStock($entity)) {
            return;
        }

        $id = (string) $entity->getId();

        if ($id === '') {
            return;
        }

        $this->removed[] = [
            'type' => $this->sourceType($entity),
            'id' => $id,
            'reason' => $reason,
            'note' => (string) $note,
        ];
    }

    /**
     * Whether this document's business has asked for its stock to be kept live.
     *
     * Off by default and set per business, so an update landing does not change
     * how anybody's day works. A vendor running on Tally carries on importing;
     * one who has never used Tally works entirely in here. Nothing about this
     * listener runs for a business that has not switched it on.
     */
    private function tracksStock(Invoice | Purchase | CreditNote $document): bool
    {
        try {
            return $document->getCompany()->hasLiveStock();
        } catch (Throwable) {
            // A document whose company is not loaded (or not set yet) is not
            // something to guess about.
            return false;
        }
    }

    private function sourceType(Invoice | Purchase | CreditNote $document): string
    {
        return match (true) {
            $document instanceof Invoice => StockPoster::SOURCE_INVOICE,
            $document instanceof Purchase => StockPoster::SOURCE_PURCHASE,
            $document instanceof CreditNote => StockPoster::SOURCE_CREDIT_NOTE,
        };
    }
}
