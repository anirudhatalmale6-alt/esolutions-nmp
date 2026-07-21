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

use SolidInvoice\CoreBundle\Enum\OrderStatus;
use SolidInvoice\CoreBundle\Repository\StoreOrderRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ListOrders
{
    public function __construct(
        private StoreOrderRepository $orderRepository,
    ) {
    }

    /**
     * @return array{orders: list<\SolidInvoice\CoreBundle\Entity\StoreOrder>, counts: array<string, int>, total: int, activeStatus: ?OrderStatus, statuses: list<OrderStatus>, csrfIntent: string}
     */
    #[Template('@SolidInvoiceCore/Order/list.html.twig')]
    public function __invoke(Request $request): array
    {
        $status = OrderStatus::tryFrom((string) $request->query->get('status', ''));

        $orders = $status instanceof OrderStatus
            ? $this->orderRepository->findByStatusOrdered($status)
            : $this->orderRepository->findAllOrdered();

        $counts = $this->orderRepository->countByStatus();

        return [
            'orders' => $orders,
            'counts' => $counts,
            'total' => array_sum($counts),
            'activeStatus' => $status,
            'statuses' => OrderStatus::cases(),
            'csrfIntent' => 'order.manage',
        ];
    }
}
