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

namespace SolidInvoice\CoreBundle\Action\Order;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Entity\StoreOrder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeleteOrder extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (! $this->isCsrfTokenValid('order.manage', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_orders_list');
        }

        $order = $this->entityManager->find(StoreOrder::class, $id);

        if (! $order instanceof StoreOrder) {
            $this->addFlash('error', 'Order not found.');

            return $this->redirectToRoute('_orders_list');
        }

        $number = $order->getOrderNumber();

        $this->entityManager->remove($order);
        $this->entityManager->flush();

        $this->addFlash('success', $number . ' deleted.');

        return $this->redirectToRoute('_orders_list');
    }
}
