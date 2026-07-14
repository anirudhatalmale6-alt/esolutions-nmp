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

namespace SolidInvoice\CoreBundle\Action\Purchase;

use SolidInvoice\CoreBundle\Entity\Purchase;
use SolidInvoice\CoreBundle\Repository\PurchaseRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * Renders a single purchase order as a printable document (a supplier-side copy
 * of the invoice layout), so it can be printed or saved to PDF from the browser.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ViewPurchase
{
    public function __construct(
        private PurchaseRepository $purchaseRepository,
    ) {
    }

    /**
     * @return array{purchase: Purchase}
     */
    #[Template('@SolidInvoiceCore/Purchase/view.html.twig')]
    public function __invoke(string $id): array
    {
        if (! Ulid::isValid($id)) {
            throw new NotFoundHttpException();
        }

        $purchase = $this->purchaseRepository->find(Ulid::fromString($id));

        if (! $purchase instanceof Purchase) {
            throw new NotFoundHttpException();
        }

        return ['purchase' => $purchase];
    }
}
